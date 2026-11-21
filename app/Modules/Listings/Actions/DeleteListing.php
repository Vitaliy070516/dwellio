<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\Listings\Models\Listing;

final class DeleteListing
{
    /** Soft delete: вектори й історія лишаються, з пошуку квартира зникає. */
    public function handle(Listing $listing): void
    {
        $listing->delete();
    }
}
