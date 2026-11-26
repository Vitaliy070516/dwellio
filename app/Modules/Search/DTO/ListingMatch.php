<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Search\Enums\MatchSource;

/**
 * Чому квартира у видачі: які гілки її знайшли, на якій позиції в кожній, і підсумковий RRF-скор.
 * Для демо («знайдено за змістом, хоча слів збігу немає») і для дебагу ранжування.
 */
final readonly class ListingMatch
{
    /**
     * @param  array<value-of<MatchSource>, int>  $ranks  гілка → позиція в ній (1 = найкраща)
     */
    public function __construct(
        public int $listingId,
        public float $score,
        public array $ranks,
    ) {}

    /** @return list<MatchSource> у порядку оголошення enum-а, щоб відповідь була стабільною */
    public function matchedBy(): array
    {
        return array_values(array_filter(
            MatchSource::cases(),
            fn (MatchSource $source): bool => isset($this->ranks[$source->value]),
        ));
    }
}
