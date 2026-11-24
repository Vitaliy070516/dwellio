<?php

declare(strict_types=1);

namespace App\Modules\AI;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // singleton: Fake має накопичувати виклики між резолвами; адаптер безстановий.
        $this->app->singleton(EmbeddingClient::class, function (): EmbeddingClient {
            $dimensions = config()->integer('dwellio.ai.embeddings.dimensions');
            $driver = config()->string('dwellio.ai.embeddings.driver');

            return match ($driver) {
                'fake' => new FakeEmbeddingClient($dimensions),
                'laravel-ai' => new LaravelAiEmbeddingClient(
                    provider: config()->string('dwellio.ai.embeddings.provider'),
                    model: config()->string('dwellio.ai.embeddings.model'),
                    dimensions: $dimensions,
                    timeout: config()->integer('dwellio.ai.embeddings.timeout'),
                ),
                default => throw new InvalidArgumentException(
                    sprintf('Unknown AI embedding driver "%s". Use "laravel-ai" or "fake".', $driver),
                ),
            };
        });
    }
}
