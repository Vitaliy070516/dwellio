<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

function aiClient(int $dimensions = 1024): LaravelAiEmbeddingClient
{
    return new LaravelAiEmbeddingClient(provider: 'ollama', model: 'bge-m3', dimensions: $dimensions);
}

it('maps a provider response to an Embedding and sends model and dimensions', function (): void {
    // Масив фейку = список ВІДПОВІДЕЙ, кожна відповідь = список векторів.
    Embeddings::fake([[array_fill(0, 1024, 0.25)]]);

    $embedding = aiClient()->embed('тиха квартира, можна з котом');

    expect($embedding->dimensions())->toBe(1024)
        ->and($embedding->model)->toBe('bge-m3')
        ->and($embedding->vector[0])->toBe(0.25);

    Embeddings::assertGenerated(
        fn ($prompt): bool => $prompt->dimensions === 1024
            && $prompt->model === 'bge-m3'
            && $prompt->inputs === ['тиха квартира, можна з котом'],
    );
});

it('wraps a provider outage into a retryable EmbeddingFailedException', function (): void {
    Embeddings::fake(fn () => throw new RuntimeException('Connection refused'));

    expect(fn () => aiClient()->embed('текст'))
        ->toThrow(EmbeddingFailedException::class, 'Connection refused');
});

it('keeps the original exception as previous', function (): void {
    Embeddings::fake(fn () => throw new RuntimeException('boom'));

    try {
        aiClient()->embed('текст');
    } catch (EmbeddingFailedException $e) {
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class);

        return;
    }

    $this->fail('EmbeddingFailedException was not thrown.');
});

it('rejects a vector with the wrong dimensionality as a permanent error', function (): void {
    Embeddings::fake([[[0.1, 0.2, 0.3]]]);

    expect(fn () => aiClient()->embed('текст'))
        ->toThrow(InvalidEmbeddingException::class, 'Expected 1024 dimensions');
});

it('rejects non-finite values', function (): void {
    Embeddings::fake([[[...array_fill(0, 1023, 0.1), NAN]]]);

    expect(fn () => aiClient()->embed('текст'))
        ->toThrow(InvalidEmbeddingException::class, 'non-finite');
});

it('rejects a response with an unexpected number of embeddings', function (): void {
    Embeddings::fake([[array_fill(0, 1024, 0.1), array_fill(0, 1024, 0.2)]]);

    expect(fn () => aiClient()->embed('текст'))
        ->toThrow(InvalidEmbeddingException::class, 'Expected 1 embedding');
});

it('does not call the provider for empty text', function (): void {
    Embeddings::fake();

    expect(fn () => aiClient()->embed("   \n"))->toThrow(InvalidEmbeddingException::class);

    Embeddings::assertNothingGenerated();
});

it('passes the provider token usage through for cost accounting', function (): void {
    Embeddings::fake(fn () => new EmbeddingsResponse(
        [array_fill(0, 1024, 0.1)],
        new Usage(inputTokens: 17),
        new Meta('ollama', 'bge-m3'),
    ));

    expect(aiClient()->embed('текст')->inputTokens)->toBe(17);
});

it('accepts integer components from JSON (Ollama may return 0 instead of 0.0)', function (): void {
    Embeddings::fake([[array_fill(0, 1024, 0)]]);

    $embedding = aiClient()->embed('текст');

    expect($embedding->vector[0])->toBeFloat()->toBe(0.0)
        ->and($embedding->dimensions())->toBe(1024);
});

it('forwards the configured timeout to the provider', function (): void {
    Embeddings::fake();

    (new LaravelAiEmbeddingClient(provider: 'ollama', model: 'bge-m3', dimensions: 1024, timeout: 7))->embed('текст');

    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->timeout === 7);
});

it('treats any provider-side Throwable, even a TypeError, as a retryable failure', function (): void {
    Embeddings::fake(fn () => throw new TypeError('unexpected payload shape'));

    expect(fn () => aiClient()->embed('текст'))->toThrow(EmbeddingFailedException::class, 'unexpected payload shape');
});

it('treats an empty vector from the provider as a permanent error', function (): void {
    Embeddings::fake([[[]]]);

    expect(fn () => aiClient()->embed('текст'))->toThrow(InvalidEmbeddingException::class);
});
