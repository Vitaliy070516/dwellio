<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Search\Enums\SearchCache;

final readonly class CacheHitRate
{
    public function __construct(
        public SearchCache $cache,
        public int $hits,
        public int $misses,
    ) {}

    public function lookups(): int
    {
        return $this->hits + $this->misses;
    }

    /** null = звернень не було (а не 0% влучань). */
    public function rate(): ?float
    {
        return $this->lookups() > 0 ? $this->hits / $this->lookups() : null;
    }
}
