<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\DTO\Embedding;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Адаптер над laravel/ai (ADR-004). Провайдер і модель (Ollama `bge-m3` локально,
 * Voyage / OpenAI у проді) приходять із конфігу. Тут же перевіряємо відповідь:
 * вектор неправильної розмірності не має дійти до колонки vector(N).
 */
final readonly class LaravelAiEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private string $provider,
        private string $model,
        private int $dimensions,
        private int $timeout = 30,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(string $text): Embedding
    {
        if (trim($text) === '') {
            throw new InvalidEmbeddingException('Cannot embed empty text.');
        }

        try {
            $response = Embeddings::for([$text])
                ->dimensions($this->dimensions)
                ->timeout($this->timeout)
                ->generate($this->provider, $this->model);
        } catch (Throwable $e) {
            throw new EmbeddingFailedException(
                sprintf('Embedding provider "%s" failed: %s', $this->provider, $e->getMessage()),
                previous: $e,
            );
        }

        if (count($response->embeddings) !== 1) {
            throw new InvalidEmbeddingException(
                sprintf('Expected 1 embedding, provider returned %d.', count($response->embeddings)),
            );
        }

        $vector = [];
        foreach ($response->embeddings[0] as $value) {
            $float = (float) $value;

            if (! is_finite($float)) {
                throw new InvalidEmbeddingException('Embedding contains a non-finite value.');
            }

            $vector[] = $float;
        }

        if (count($vector) !== $this->dimensions) {
            throw new InvalidEmbeddingException(sprintf(
                'Expected %d dimensions from "%s/%s", got %d.',
                $this->dimensions,
                $this->provider,
                $this->model,
                count($vector),
            ));
        }

        return new Embedding($vector, $this->model, $response->usage->inputTokens);
    }
}
