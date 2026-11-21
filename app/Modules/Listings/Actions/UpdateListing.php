<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\Listings\DTO\ListingData;
use App\Modules\Listings\Models\Listing;

final class UpdateListing
{
    public function handle(Listing $listing, ListingData $data): Listing
    {
        $listing->update($data->toAttributes());

        return $listing;
    }
}
