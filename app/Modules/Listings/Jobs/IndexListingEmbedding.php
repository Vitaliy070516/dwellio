<?php

declare(strict_types=1);

namespace App\Modules\Listings\Jobs;

use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\Listings\Actions\EmbedListing;
use App\Modules\Listings\Models\Listing;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Асинхронна індексація квартири через RabbitMQ (черга `embeddings`).
 *
 * Повтори: збій провайдера кидає виняток, воркер повторює з наростаючою паузою (backoff).
 * Після 3 винятків або 15 хвилин Job падає, і RabbitMQ через DLX кладе його в `embeddings.failed`.
 * Постійні помилки (InvalidEmbeddingException) падають одразу, без повторів.
 * Вичерпаний денний бюджет — теж одразу в DLQ: retryUntil (15 хв) до півночі не дотягне,
 * а `listings:reindex` наступного дня підбере квартири без ембединга (зі зміненим текстом — `--all`).
 */
final class IndexListingEmbedding implements ShouldQueue
{
    use Queueable;

    /**
     * Ліміт саме ВИНЯТКІВ, а не спроб: `tries` рахує й «відкладення» від WithoutOverlapping,
     * тож Job, що чекав на сусіда, міг би вичерпати спроби, жодного разу не впавши.
     */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $listingId)
    {
        $this->onQueue('embeddings');
    }

    /**
     * @return list<int> секунди паузи перед 2-ю, 3-ю... спробою
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /** Загальна стеля життя Job-а (замінює `tries`, див. $maxExceptions). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * Один Job на квартиру за раз: два PUT підряд не змагаються, і старий текст не перезапише новий
     * (другий Job стартує після першого й заново читає актуальний текст).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("listing-embedding:{$this->listingId}"))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(EmbedListing $embed): void
    {
        // find() не бачить soft-deleted: квартиру могли видалити, поки Job стояв у черзі.
        $listing = Listing::query()->find($this->listingId);

        if ($listing === null) {
            Log::info('listing embedding skipped: listing is gone', ['listing_id' => $this->listingId]);

            return;
        }

        try {
            $outcome = $embed->handle($listing);
        } catch (InvalidEmbeddingException|TokenBudgetExceededException $e) {
            // Повтор не допоможе (наприклад, вектор неправильної розмірності): одразу в DLQ.
            $this->fail($e);

            return;
        }

        Log::info('listing embedding processed', [
            'listing_id' => $this->listingId,
            'outcome' => $outcome->value,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // Без тексту оголошення в логах: лише id і причина (JSON-логи йдуть у Loki).
        Log::error('listing embedding failed', [
            'listing_id' => $this->listingId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
