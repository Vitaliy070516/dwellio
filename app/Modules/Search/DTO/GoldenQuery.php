<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

/**
 * Еталонний запит: текст, який вводить користувач, і ПРАВИЛО релевантності.
 *
 * Релевантність задано предикатом (фільтри + групи слів), а не списком id: дані синтетичні
 * (ListingFactory), id залежать від порядку сідування, а правило переживає `migrate:fresh --seed`.
 * Групи слів — AND між групами, OR усередині: [["тих", "спокійн"], ["ремонт"]] = тиша І ремонт.
 */
final readonly class GoldenQuery
{
    /**
     * @param  list<non-empty-list<non-empty-string>>  $textGroups
     */
    public function __construct(
        public string $id,
        public string $query,
        public SearchFilters $relevantFilters,
        public array $textGroups,
    ) {}
}
