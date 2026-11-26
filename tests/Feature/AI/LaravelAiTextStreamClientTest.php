<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\DTO\TextStreamResult;
use App\Modules\AI\Enums\FinishReason;
use App\Modules\AI\Exceptions\TextStreamFailedException;
use App\Modules\AI\Services\FakeTextStreamClient;
use App\Modules\AI\Services\LaravelAiTextStreamClient;
use App\Modules\AI\Services\TextStreamAgent;

/**
 * @return array{string, TextStreamResult}
 */
function consumeStream(Generator $stream): array
{
    $text = '';
    foreach ($stream as $delta) {
        $text .= $delta;
    }

    return [$text, $stream->getReturn()];
}

it('turns SDK stream events into plain text deltas and a final result', function (): void {
    TextStreamAgent::fake(['Світла квартира біля метро']);

    [$text, $result] = consumeStream(
        new LaravelAiTextStreamClient('ollama', 'qwen2.5:3b')->stream('instructions', 'prompt', 500),
    );

    expect($text)->toBe('Світла квартира біля метро')
        ->and($result->finishReason)->toBe(FinishReason::Stop)
        ->and($result->completed())->toBeTrue()
        ->and($result->model)->toBe('qwen2.5:3b');
});

it('wraps any SDK failure into the module exception', function (): void {
    TextStreamAgent::fake(fn () => throw new RuntimeException('cURL error 28: Operation timed out'));

    consumeStream(new LaravelAiTextStreamClient('ollama', 'qwen2.5:3b')->stream('instructions', 'prompt', 500));
})->throws(TextStreamFailedException::class, 'timed out');

it('maps unknown provider finish reasons to Unknown, never to success', function (string $reason): void {
    expect(FinishReason::fromProvider($reason))->toBe(FinishReason::Unknown);
})->with(['tool_calls', 'cancelled', 'error', '']);

it('resolves the fake client in tests so no test can reach a real LLM', function (): void {
    expect(app(TextStreamClient::class))->toBeInstanceOf(FakeTextStreamClient::class);
});

it('refuses an unknown generation driver loudly', function (): void {
    config(['dwellio.ai.generation.driver' => 'openai-direct']);
    app()->forgetInstance(TextStreamClient::class);

    app(TextStreamClient::class);
})->throws(InvalidArgumentException::class, 'Unknown AI generation driver');
