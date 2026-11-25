<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\Listings\Jobs\IndexListingEmbedding;
use App\Modules\Listings\Models\Listing;
use Throwable;

/**
 * Ставить індексацію квартири в чергу. Збій черги (RabbitMQ недоступний) НЕ валить запит:
 * квартира вже збережена, а відсутній вектор дозорахує `listings:reindex`.
 * Доступність API важливіша за свіжість індексу.
 */
final readonly class ScheduleListingEmbedding
{
    public function handle(Listing $listing): void
    {
        // Чернетки й архів не індексуємо: їх не шукають, а токени коштують грошей.
        if (! $listing->status->isSearchable()) {
            return;
        }

        try {
            IndexListingEmbedding::dispatch($listing->id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
