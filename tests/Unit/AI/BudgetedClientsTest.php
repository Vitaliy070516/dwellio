<?php

declare(strict_types=1);

use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use App\Modules\AI\Exceptions\TextStreamFailedException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\AI\Services\BudgetedEmbeddingClient;
use App\Modules\AI\Services\BudgetedStructuredOutputClient;
use App\Modules\AI\Services\BudgetedTextStreamClient;
use App\Modules\AI\Services\CacheTokenBudget;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\FakeStructuredOutputClient;
use App\Modules\AI\Services\FakeTextStreamClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function unitBudget(int $limit = 10_000): CacheTokenBudget
{
    return new CacheTokenBudget(new Repository(new ArrayStore), [
        'embeddings' => $limit, 'query_parsing' => $limit, 'generation' => $limit,
    ]);
}

it('records embedding tokens after a successful call', function (): void {
    $budget = unitBudget();
    $client = new BudgetedEmbeddingClient(new FakeEmbeddingClient(8), $budget);

    $embedding = $client->embed('тиха квартира біля метро');

    expect($budget->usage(AiPurpose::Embeddings)->inputTokens)->toBe($embedding->inputTokens)
        ->and($budget->usage(AiPurpose::Embeddings)->calls)->toBe(1)
        ->and($client->model())->toBe($client->inner->model());
});

it('does not reach the provider when the budget is exhausted', function (): void {
    $budget = unitBudget(5);
    $budget->record(AiPurpose::Embeddings, 5, 0);
    $fake = new FakeEmbeddingClient(8);

    expect(fn () => new BudgetedEmbeddingClient($fake, $budget)->embed('text'))
        ->toThrow(TokenBudgetExceededException::class);
    expect($fake->embeddedTexts())->toBe([]);
});

it('records nothing when the provider fails', function (): void {
    $budget = unitBudget();
    $fake = (new FakeEmbeddingClient(8))->failNextWith(new EmbeddingFailedException('timeout'));

    expect(fn () => new BudgetedEmbeddingClient($fake, $budget)->embed('text'))->toThrow(EmbeddingFailedException::class);
    expect($budget->usage(AiPurpose::Embeddings)->calls)->toBe(0);
});

it('records structured-output tokens and passes the data through untouched', function (): void {
    $budget = unitBudget();
    $fake = (new FakeStructuredOutputClient)->respondWith(['rooms' => [2]]);

    $output = new BudgetedStructuredOutputClient($fake, $budget)->generate('i', 'двокімнатна на Подолі', fn () => []);

    expect($output->data)->toBe(['rooms' => [2]])
        ->and($budget->usage(AiPurpose::QueryParsing)->total())->toBe($output->inputTokens + $output->outputTokens)
        ->and($output->inputTokens)->toBeGreaterThan(0);
});

it('blocks structured output on an exhausted budget without calling the model', function (): void {
    $budget = unitBudget(1);
    $budget->record(AiPurpose::QueryParsing, 1, 0);
    $fake = new FakeStructuredOutputClient;

    expect(fn () => new BudgetedStructuredOutputClient($fake, $budget)->generate('i', 'p', fn () => []))
        ->toThrow(TokenBudgetExceededException::class);
    expect($fake->prompts())->toBe([]);
});

it('lets structured-output failures through unchanged', function (): void {
    $fake = (new FakeStructuredOutputClient)->failNextWith(new StructuredOutputFailedException('bad json'));

    new BudgetedStructuredOutputClient($fake, unitBudget())->generate('i', 'p', fn () => []);
})->throws(StructuredOutputFailedException::class, 'bad json');

it('streams every chunk and records tokens from the final result', function (): void {
    $budget = unitBudget();
    $fake = (new FakeTextStreamClient)->pushResponse(['Світла ', 'квартира']);
    $stream = new BudgetedTextStreamClient($fake, $budget)->stream('i', 'prompt', 500);

    $text = implode('', iterator_to_array($stream, false));
    $result = $stream->getReturn();

    expect($text)->toBe('Світла квартира')
        ->and($budget->usage(AiPurpose::Generation)->outputTokens)->toBe($result->outputTokens)
        ->and($budget->usage(AiPurpose::Generation)->inputTokens)->toBe($result->inputTokens);
});

it('checks the stream budget before the provider sees the request', function (): void {
    $budget = unitBudget(1);
    $budget->record(AiPurpose::Generation, 1, 0);
    $fake = new FakeTextStreamClient;
    $stream = new BudgetedTextStreamClient($fake, $budget)->stream('i', 'p', 500);

    expect(fn () => $stream->current())->toThrow(TokenBudgetExceededException::class);
    expect($fake->calls())->toBe([]);
});

it('records nothing for a stream that broke midway (no final usage event)', function (): void {
    $budget = unitBudget();
    $fake = (new FakeTextStreamClient)->pushFailure(new TextStreamFailedException('reset'), ['Світла ']);
    $stream = new BudgetedTextStreamClient($fake, $budget)->stream('i', 'p', 500);

    expect(fn () => iterator_to_array($stream, false))->toThrow(TextStreamFailedException::class);
    expect($budget->usage(AiPurpose::Generation)->calls)->toBe(0);
});
