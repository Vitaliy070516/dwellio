<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\Listings\DTO\ListingData;
use App\Modules\Listings\Models\Listing;

final readonly class CreateListing
{
    public function __construct(private ScheduleListingEmbedding $scheduleEmbedding) {}

    public function handle(ListingData $data): Listing
    {
        $listing = Listing::query()->create($data->toAttributes());

        $this->scheduleEmbedding->handle($listing);

        return $listing;
    }
}
