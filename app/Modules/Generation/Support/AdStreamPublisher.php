<?php

declare(strict_types=1);

namespace App\Modules\Generation\Support;

use App\Modules\Generation\Enums\AdFailure;
use App\Modules\Generation\Events\AdChunkGenerated;
use App\Modules\Generation\Events\AdGenerationCompleted;
use App\Modules\Generation\Events\AdGenerationEvent;
use App\Modules\Generation\Events\AdGenerationFailed;
use App\Modules\Generation\Events\AdGenerationStarted;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Публікує події однієї спроби генерації. Недоступний Reverb НЕ валить генерацію:
 * результат усе одно ляже в БД, а браузер забере його через GET.
 * Після кількох збоїв поспіль чанки перестають відправлятись, щоб таймаути HTTP до Reverb
 * не гальмували читання стріму LLM. Фінальні події (completed/failed) пробуємо завжди.
 */
final class AdStreamPublisher
{
    private int $seq = 0;

    private int $consecutiveFailures = 0;

    public private(set) int $chunksSent = 0;

    public private(set) int $failures = 0;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly string $adId,
        private readonly int $attempt,
        private readonly int $maxConsecutiveFailures,
    ) {}

    public function started(): void
    {
        $this->send(new AdGenerationStarted($this->adId, $this->attempt));
    }

    public function chunk(string $text): void
    {
        if ($this->chunksDisabled()) {
            return;
        }

        // seq росте навіть при збої відправки: пропуск у seq — саме те, що має побачити клієнт.
        $this->seq++;

        if ($this->send(new AdChunkGenerated($this->adId, $this->attempt, $this->seq, $text))) {
            $this->chunksSent++;
        }
    }

    public function completed(): void
    {
        $this->send(new AdGenerationCompleted($this->adId));
    }

    public function failed(AdFailure $failure): void
    {
        $this->send(new AdGenerationFailed($this->adId, $failure));
    }

    public function chunksDisabled(): bool
    {
        return $this->consecutiveFailures >= $this->maxConsecutiveFailures;
    }

    private function send(AdGenerationEvent $event): bool
    {
        try {
            $this->events->dispatch($event);
            $this->consecutiveFailures = 0;

            return true;
        } catch (Throwable $e) {
            $this->failures++;
            $this->consecutiveFailures++;

            Log::warning('ad broadcast failed', [
                'ad_id' => $this->adId,
                'event' => $event->broadcastAs(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
