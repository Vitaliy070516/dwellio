<?php

declare(strict_types=1);

namespace App\Modules\Generation\Jobs;

use App\Modules\AI\Exceptions\TextStreamFailedException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\Generation\Actions\GenerateListingAd;
use App\Modules\Generation\Enums\AdFailure;
use App\Modules\Generation\Exceptions\InvalidAdOutputException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Генерація оголошення через RabbitMQ (черга `generation`, окремий воркер).
 *
 * Ланцюжок таймаутів строго зростає (ADR-002), інакше деплой або k8s обірве стрім:
 *   LLM 180 с < $timeout 240 с < `queue:work --timeout=270` < stop_grace_period 300 с.
 *
 * Кожна спроба коштує токенів, тож повторів мало: 2 винятки → DLQ `generation.failed`.
 */
final class GenerateListingAdJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    /**
     * Перевищення $timeout → одразу failed(), а не повторна доставка: повільна модель
     * найімовірніше буде повільною й наступного разу, а платити двічі не хочемо.
     */
    public bool $failOnTimeout = true;

    /** Ліміт саме винятків: «відкладення» від WithoutOverlapping не повинні з'їдати спроби. */
    public int $maxExceptions = 2;

    public function __construct(public readonly string $adId)
    {
        $this->onQueue('generation');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /** Те саме значення, що й dwellio.generation.stale_after_seconds. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * Одна спроба на запис за раз: повторна доставка (воркер помер, RabbitMQ віддав повідомлення
     * іншому) не запускає другий паралельний стрім. expireAfter > $timeout: lock переживає Job.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("listing-ad:{$this->adId}"))
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    public function handle(GenerateListingAd $generate): void
    {
        try {
            $generate->handle($this->adId);
        } catch (TokenBudgetExceededException $e) {
            // До опівночі UTC повтор нічого не дасть: одразу failed(), без ретраїв і без очікування в черзі.
            $this->fail($e);
        }
    }

    public function failed(Throwable $exception): void
    {
        $failure = match (true) {
            $exception instanceof TokenBudgetExceededException => AdFailure::BudgetExceeded,
            $exception instanceof TextStreamFailedException => AdFailure::ProviderUnavailable,
            $exception instanceof InvalidAdOutputException => AdFailure::InvalidOutput,
            $exception instanceof TimeoutExceededException,
            $exception instanceof MaxAttemptsExceededException => AdFailure::Timeout,
            default => AdFailure::Unknown,
        };

        Log::error('ad generation failed', [
            'ad_id' => $this->adId,
            'failure' => $failure->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        app(GenerateListingAd::class)->fail($this->adId, $failure);
    }
}
