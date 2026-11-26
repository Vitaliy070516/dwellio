<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\EmbeddingFailedException;
use Illuminate\Support\Facades\Http;

it('demo:check fails outside a prepared demo and names what is wrong', function (): void {
    Http::fake(['*' => Http::response(status: 422)]);

    $this->artisan('demo:check')
        ->expectsOutputToContain('DEMO_MODE=true')
        ->expectsOutputToContain('config is cached')
        ->expectsOutputToContain('do not expose the tunnel')
        ->assertFailed();
});

it('demo:check asks the web process itself, not only its own config', function (int $status, string $code, string $mark): void {
    Http::fake(['localhost/api/listings' => Http::response(['code' => $code], $status)]);

    $this->artisan('demo:check')
        ->expectsOutputToContain($mark.' web process answers in demo mode')
        ->assertFailed(); // решта пунктів у тестовому оточенні не виконана (кеш конфігу, APP_ENV…)

    Http::assertSent(static fn ($request): bool => $request->method() === 'POST' && $request->url() === 'http://localhost/api/listings');
})->with([
    'web in demo' => [403, 'demo_read_only', '✔'],
    // Саме цей випадок зловили вживу: CLI у demo, а artisan serve — на звичайному .env.
    'web on plain .env' => [422, 'validation_failed', '✘'],
]);

it('demo:check rejects a wildcard trusted proxy', function (): void {
    config(['trustedproxy.proxies' => '*']);
    Http::fake(['*' => Http::response(status: 422)]);

    $this->artisan('demo:check')->expectsOutputToContain('trusted proxy is pinned')->assertFailed();
});

it('ai:warmup loads both models through the budgeted clients', function (): void {
    $this->artisan('ai:warmup')
        ->expectsOutputToContain('embeddings: warm')
        ->expectsOutputToContain('query parsing LLM: warm')
        ->assertSuccessful();
});

it('ai:warmup reports a provider failure without crashing and still warms the other model', function (): void {
    fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('ollama is cold'));

    $this->artisan('ai:warmup')
        ->expectsOutputToContain('embeddings: failed')
        ->expectsOutputToContain('query parsing LLM: warm')
        ->assertFailed();
});
