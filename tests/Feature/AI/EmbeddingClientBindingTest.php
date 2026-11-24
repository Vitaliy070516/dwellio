<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;

it('uses the fake client in the test environment and keeps one shared instance', function (): void {
    $client = app(EmbeddingClient::class);

    expect($client)->toBeInstanceOf(FakeEmbeddingClient::class)
        ->and(app(EmbeddingClient::class))->toBe($client);
});

it('switches to the real adapter through config', function (): void {
    config(['dwellio.ai.embeddings.driver' => 'laravel-ai']);
    app()->forgetInstance(EmbeddingClient::class);

    expect(app(EmbeddingClient::class))->toBeInstanceOf(LaravelAiEmbeddingClient::class);
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
