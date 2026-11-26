<?php

declare(strict_types=1);

namespace App\Modules\AI\Console;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\StructuredOutputClient;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Прогрів моделей перед демо: перший запит до Ollama вантажить модель у пам'ять (~25 с для bge-m3,
 * секунди для qwen), і цей час інакше дістається першому гостю, а `GET /api/search` деградує.
 *
 * Йде через ті самі контракти, що й застосунок (з обліком токенів): прогрів платного провайдера
 * коштує копійки й чесно потрапляє в `ai:usage`. Збій не фатальний — пошук має fallback-и.
 */
final class WarmUpModels extends Command
{
    protected $signature = 'ai:warmup';

    protected $description = 'Load the embedding and LLM models into memory before a demo (first-request latency)';

    /** Холодне завантаження моделі в Ollama — десятки секунд; гарячий таймаут розбору запиту (10 с) тут не підходить. */
    private const int WARMUP_TIMEOUT_SECONDS = 180;

    public function handle(): int
    {
        // Клієнти — ліниві singleton-и, що читають таймаут при створенні: піднімаємо його ДО resolve
        // і лише в цьому процесі (веб-сервер і воркери живуть зі своїми таймаутами).
        foreach (['dwellio.ai.embeddings.timeout', 'dwellio.ai.llm.timeout'] as $key) {
            config([$key => max(config()->integer($key), self::WARMUP_TIMEOUT_SECONDS)]);
        }
        $embeddings = $this->laravel->make(EmbeddingClient::class);
        $llm = $this->laravel->make(StructuredOutputClient::class);

        $ok = $this->step('embeddings', static fn () => $embeddings->embed('тиха квартира біля метро'));
        $ok = $this->step('query parsing LLM', static fn () => $llm->generate(
            'Reply with JSON.',
            'ping',
            static fn (JsonSchema $schema): array => ['ok' => $schema->boolean()],
        )) && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param  Closure(): mixed  $call */
    private function step(string $name, Closure $call): bool
    {
        $started = hrtime(true);

        try {
            $call();
        } catch (Throwable $e) {
            $this->warn(sprintf('%s: failed (%s: %s)', $name, $e::class, $e->getMessage()));

            return false;
        }

        $this->info(sprintf('%s: warm in %.1f s', $name, (hrtime(true) - $started) / 1e9));

        return true;
    }
}
