<?php

declare(strict_types=1);

namespace App\Modules\Listings\Enums;

enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /** Лише активні квартири потрапляють у пошук. */
    public function isSearchable(): bool
    {
        return $this === self::Active;
    }
}
