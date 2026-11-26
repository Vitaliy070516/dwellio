<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Listings\Models\Listing;
use App\Modules\Search\Enums\SearchMode;

final readonly class SearchResult
{
    /**
     * @param  list<Listing>  $listings  у порядку релевантності
     * @param  array<int, ListingMatch>  $matches  id квартири → пояснення збігу; порожньо в режимі FiltersOnly
     */
    public function __construct(
        public array $listings,
        public SearchMode $mode,
        public ParsedQuery $query,
        public array $matches = [],
    ) {}

    public function matchFor(Listing $listing): ?ListingMatch
    {
        return $this->matches[$listing->id] ?? null;
    }
}
