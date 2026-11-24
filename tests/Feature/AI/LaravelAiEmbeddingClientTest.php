<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;
use Laravel\Ai\Embeddings;

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
