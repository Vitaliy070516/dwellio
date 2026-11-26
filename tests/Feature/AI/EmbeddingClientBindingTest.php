<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\Services\BudgetedEmbeddingClient;
use App\Modules\AI\Services\BudgetedStructuredOutputClient;
use App\Modules\AI\Services\BudgetedTextStreamClient;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\FakeStructuredOutputClient;
use App\Modules\AI\Services\FakeTextStreamClient;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;

it('wraps the fake client in the budget decorator and keeps one shared instance', function (): void {
    $client = app(EmbeddingClient::class);

    expect($client)->toBeInstanceOf(BudgetedEmbeddingClient::class)
        ->and($client->inner)->toBe(app(FakeEmbeddingClient::class))
        ->and(app(EmbeddingClient::class))->toBe($client);
});

it('puts every AI contract behind the token budget, so no call can bypass accounting', function (): void {
    $llm = app(StructuredOutputClient::class);
    $stream = app(TextStreamClient::class);

    expect($llm)->toBeInstanceOf(BudgetedStructuredOutputClient::class)
        ->and($llm->inner)->toBeInstanceOf(FakeStructuredOutputClient::class)
        ->and($stream)->toBeInstanceOf(BudgetedTextStreamClient::class)
        ->and($stream->inner)->toBeInstanceOf(FakeTextStreamClient::class);
});

it('switches to the real adapter through config', function (): void {
    config(['dwellio.ai.embeddings.driver' => 'laravel-ai']);
    app()->forgetInstance(EmbeddingClient::class);

    $client = app(EmbeddingClient::class);

    expect($client)->toBeInstanceOf(BudgetedEmbeddingClient::class)
        ->and($client->inner)->toBeInstanceOf(LaravelAiEmbeddingClient::class);
});

it('refuses to boot with an unknown driver instead of guessing', function (): void {
    config(['dwellio.ai.embeddings.driver' => 'chatgpt']);
    app()->forgetInstance(EmbeddingClient::class);

    expect(fn () => app(EmbeddingClient::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown AI embedding driver "chatgpt"');
});

it('defaults to the real driver in config, so production never silently runs on the fake', function (): void {
    // env() у тестах уже перекрито phpunit.xml (fake), тож перевіряємо сам дефолт у файлі конфігу.
    expect(file_get_contents(base_path('config/dwellio.php')))
        ->toContain("env('AI_EMBEDDING_DRIVER', 'laravel-ai')")
        ->and(config('dwellio.ai.embeddings.dimensions'))->toBe(1024);
});
