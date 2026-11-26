<?php

declare(strict_types=1);

namespace App\Modules\AI;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Services\BudgetedEmbeddingClient;
use App\Modules\AI\Services\BudgetedStructuredOutputClient;
use App\Modules\AI\Services\BudgetedTextStreamClient;
use App\Modules\AI\Services\CacheTokenBudget;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\FakeStructuredOutputClient;
use App\Modules\AI\Services\FakeTextStreamClient;
use App\Modules\AI\Services\LaravelAiEmbeddingClient;
use App\Modules\AI\Services\LaravelAiStructuredOutputClient;
use App\Modules\AI\Services\LaravelAiTextStreamClient;
use App\Modules\AI\Services\PriceCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Кожен контракт = Budgeted-декоратор над реалізацією, обраною драйвером (laravel-ai | fake).
 * Fake-и — окремі singleton-и: тести дістають їх напряму й налаштовують відповіді,
 * а виклики все одно йдуть через декоратор, тож облік токенів перевіряється так само, як у проді.
 */
final class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenBudget::class, static function (): TokenBudget {
            $store = config('dwellio.ai.budget.store');

            $limits = [];
            foreach (AiPurpose::cases() as $purpose) {
                $limits[$purpose->value] = config()->integer("dwellio.ai.budget.daily_tokens.{$purpose->value}");
            }

            $catalog = PriceCatalog::fromConfig(config()->array('dwellio.ai.pricing'));
            $prices = [];
            foreach (AiPurpose::cases() as $purpose) {
                $section = self::configSection($purpose);
                [$driver, $provider, $model] = [
                    config()->string("dwellio.ai.{$section}.driver"),
                    config()->string("dwellio.ai.{$section}.provider"),
                    config()->string("dwellio.ai.{$section}.model"),
                ];

                if (! $catalog->knows($driver, $provider, $model)) {
                    // Раз на процес (singleton), а не на кожен виклик.
                    Log::warning('AI model has no price in dwellio.ai.pricing, using the fallback price.', [
                        'purpose' => $purpose->value, 'provider' => $provider, 'model' => $model,
                    ]);
                }

                $prices[$purpose->value] = $catalog->resolve($driver, $provider, $model);
            }

            return new CacheTokenBudget(
                cache: Cache::store(is_string($store) && $store !== '' ? $store : null),
                dailyLimits: $limits,
                prices: $prices,
                dailySpendCapMicroUsd: self::microUsd(config('dwellio.ai.budget.daily_usd')),
                monthlySpendCapMicroUsd: self::microUsd(config('dwellio.ai.budget.monthly_usd')),
            );
        });

        $this->app->singleton(FakeEmbeddingClient::class, static fn (): FakeEmbeddingClient => new FakeEmbeddingClient(
            config()->integer('dwellio.ai.embeddings.dimensions'),
        ));
        $this->app->singleton(FakeStructuredOutputClient::class);
        $this->app->singleton(FakeTextStreamClient::class);

        // singleton: декоратори безстанові, а Fake має накопичувати виклики між резолвами.
        $this->app->singleton(EmbeddingClient::class, function (): EmbeddingClient {
            $driver = config()->string('dwellio.ai.embeddings.driver');

            $inner = match ($driver) {
                'fake' => $this->app->make(FakeEmbeddingClient::class),
                'laravel-ai' => new LaravelAiEmbeddingClient(
                    provider: config()->string('dwellio.ai.embeddings.provider'),
                    model: config()->string('dwellio.ai.embeddings.model'),
                    dimensions: config()->integer('dwellio.ai.embeddings.dimensions'),
                    timeout: config()->integer('dwellio.ai.embeddings.timeout'),
                ),
                default => throw new InvalidArgumentException(
                    sprintf('Unknown AI embedding driver "%s". Use "laravel-ai" or "fake".', $driver),
                ),
            };

            return new BudgetedEmbeddingClient($inner, $this->app->make(TokenBudget::class));
        });

        $this->app->singleton(StructuredOutputClient::class, function (): StructuredOutputClient {
            $driver = config()->string('dwellio.ai.llm.driver');

            $inner = match ($driver) {
                'fake' => $this->app->make(FakeStructuredOutputClient::class),
                'laravel-ai' => new LaravelAiStructuredOutputClient(
                    provider: config()->string('dwellio.ai.llm.provider'),
                    model: config()->string('dwellio.ai.llm.model'),
                    timeout: config()->integer('dwellio.ai.llm.timeout'),
                ),
                default => throw new InvalidArgumentException(
                    sprintf('Unknown AI LLM driver "%s". Use "laravel-ai" or "fake".', $driver),
                ),
            };

            return new BudgetedStructuredOutputClient($inner, $this->app->make(TokenBudget::class));
        });

        $this->app->singleton(TextStreamClient::class, function (): TextStreamClient {
            $driver = config()->string('dwellio.ai.generation.driver');

            $inner = match ($driver) {
                'fake' => $this->app->make(FakeTextStreamClient::class),
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

            return new BudgetedTextStreamClient($inner, $this->app->make(TokenBudget::class));
        });
    }

    /** Секція config/dwellio.php → ai, де задано driver / provider / model цілі. */
    private static function configSection(AiPurpose $purpose): string
    {
        return match ($purpose) {
            AiPurpose::Embeddings => 'embeddings',
            AiPurpose::QueryParsing => 'llm',
            AiPurpose::Generation => 'generation',
        };
    }

    /** Не config()->float(): `5` і `5.0` мають бути рівнозначні. Не число → 0, тобто fail closed для платних викликів. */
    private static function microUsd(mixed $usd): int
    {
        return is_numeric($usd) ? (int) round(max(0.0, (float) $usd) * 1_000_000) : 0;
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
