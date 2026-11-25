<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Listings\Models\Listing;
use App\Modules\Search\Enums\SearchMode;

final readonly class SearchResult
{
    /**
     * @param  list<Listing>  $listings  у порядку релевантності
     */
    public function __construct(
        public array $listings,
        public SearchMode $mode,
        public ParsedQuery $query,
    ) {}
}
