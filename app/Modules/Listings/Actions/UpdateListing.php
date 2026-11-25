<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\Listings\DTO\ListingData;
use App\Modules\Listings\Models\Listing;

final readonly class UpdateListing
{
    public function __construct(private ScheduleListingEmbedding $scheduleEmbedding) {}

    public function handle(Listing $listing, ListingData $data): Listing
    {
        $listing->update($data->toAttributes());

        // Переіндексація лише коли змінилось те, з чого рахується вектор (title, description),
        // або квартира щойно стала активною. Зміна ціни чи кімнат токенів не коштує.
        if ($listing->wasChanged(['title', 'description', 'status'])) {
            $this->scheduleEmbedding->handle($listing);
        }

        return $listing;
    }
}
