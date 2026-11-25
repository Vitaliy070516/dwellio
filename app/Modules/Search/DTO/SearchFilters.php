<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Listings\Enums\District;

/**
 * Жорсткі умови пошуку (йдуть у SQL `WHERE`, а не в ранжування): «до 20 000», «2 кімнати», «Поділ».
 * Один і той самий DTO будує і LLM-парсер, і явні query-параметри API.
 */
final readonly class SearchFilters
{
    /**
     * @param  list<int>  $rooms
     * @param  list<District>  $districts
     */
    public function __construct(
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public array $rooms = [],
        public array $districts = [],
        public ?bool $petsAllowed = null,
        public ?bool $furnished = null,
        public ?int $maxMetroDistanceM = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->priceMin === null
            && $this->priceMax === null
            && $this->rooms === []
            && $this->districts === []
            && $this->petsAllowed === null
            && $this->furnished === null
            && $this->maxMetroDistanceM === null;
    }

    /** Явні параметри користувача завжди сильніші за здогадку LLM: перекривають поле за полем. */
    public function overriddenBy(self $explicit): self
    {
        return new self(
            priceMin: $explicit->priceMin ?? $this->priceMin,
            priceMax: $explicit->priceMax ?? $this->priceMax,
            rooms: $explicit->rooms !== [] ? $explicit->rooms : $this->rooms,
            districts: $explicit->districts !== [] ? $explicit->districts : $this->districts,
            petsAllowed: $explicit->petsAllowed ?? $this->petsAllowed,
            furnished: $explicit->furnished ?? $this->furnished,
            maxMetroDistanceM: $explicit->maxMetroDistanceM ?? $this->maxMetroDistanceM,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'rooms' => $this->rooms,
            'districts' => array_map(static fn (District $d): string => $d->value, $this->districts),
            'pets_allowed' => $this->petsAllowed,
            'furnished' => $this->furnished,
            'max_metro_distance_m' => $this->maxMetroDistanceM,
        ];
    }
}
