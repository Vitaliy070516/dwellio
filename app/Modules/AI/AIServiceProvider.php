<?php

declare(strict_types=1);

namespace App\Modules\AI;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\FakeStructuredOutputClient;
use App\Modules\AI\Services\FakeTextStreamClient;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;
use App\Modules\AI\Services\LaravelAiStructuredOutputClient;
use App\Modules\AI\Services\LaravelAiTextStreamClient;
use Illuminate\Support\Facades\Http;
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

        $this->app->singleton(StructuredOutputClient::class, function (): StructuredOutputClient {
            $driver = config()->string('dwellio.ai.llm.driver');

            return match ($driver) {
                'fake' => new FakeStructuredOutputClient,
                'laravel-ai' => new LaravelAiStructuredOutputClient(
                    provider: config()->string('dwellio.ai.llm.provider'),
                    model: config()->string('dwellio.ai.llm.model'),
                    timeout: config()->integer('dwellio.ai.llm.timeout'),
                ),
                default => throw new InvalidArgumentException(
                    sprintf('Unknown AI LLM driver "%s". Use "laravel-ai" or "fake".', $driver),
                ),
            };
        });

        $this->app->singleton(TextStreamClient::class, function (): TextStreamClient {
            $driver = config()->string('dwellio.ai.generation.driver');

            return match ($driver) {
                'fake' => new FakeTextStreamClient,
                'laravel-ai' => new LaravelAiTextStreamClient(
                    provider: config()->string('dwellio.ai.generation.provider'),
                    model: config()->string('dwellio.ai.generation.model'),
                    timeout: config()->integer('dwellio.ai.generation.timeout'),
                    temperature: config()->float('dwellio.ai.generation.temperature'),
                ),
                default => throw new InvalidArgumentException(
                    sprintf('Unknown AI generation driver "%s". Use "laravel-ai" or "fake".', $driver),
                ),
            };
        });
    }

    public function boot(): void
    {
        /*
         * Стрімінг у laravel/ai йде з `stream => true`, а для таких запитів Guzzle 8 вмикає StreamHandler
         * з idle-таймаутом 60 с (опція `read_timeout`, НЕ default_socket_timeout і НЕ `timeout`).
         * Холодний старт моделі в Ollama ~90 с без жодного байта → обрив «Could not connect» рівно на 60-й секунді.
         * SDK не дає передати опції Guzzle, тож задаємо їх глобально для HTTP-клієнта Laravel.
         * Curl-хендлер (звичайні, не потокові запити) `read_timeout` ігнорує, тож на решту викликів це не впливає.
         */
        Http::globalOptions(['read_timeout' => config()->integer('dwellio.ai.generation.timeout')]);
    }
}
