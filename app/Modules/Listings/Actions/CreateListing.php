<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\Listings\DTO\ListingData;
use App\Modules\Listings\Models\Listing;

final class CreateListing
{
    public function handle(ListingData $data): Listing
    {
        return Listing::query()->create($data->toAttributes());
    }
}
