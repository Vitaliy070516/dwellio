<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

/**
 * Результат розбору запиту: жорсткі фільтри + «семантичний залишок» для векторного й текстового пошуку.
 */
final readonly class ParsedQuery
{
    public function __construct(
        public SearchFilters $filters,
        public string $semanticText,
        public bool $parsedByLlm,
    ) {}
}
