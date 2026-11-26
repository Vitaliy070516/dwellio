<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Search\Enums\DegradationReason;

/**
 * Результат розбору запиту: жорсткі фільтри + «семантичний залишок» для векторного й текстового пошуку.
 */
final readonly class ParsedQuery
{
    /**
     * @param  DegradationReason|null  $failure  чому LLM не розібрала запит; null — розібрала або й не мала (порожній запит)
     */
    public function __construct(
        public SearchFilters $filters,
        public string $semanticText,
        public bool $parsedByLlm,
        public ?DegradationReason $failure = null,
    ) {}

    public function withFilters(SearchFilters $filters): self
    {
        return new self($filters, $this->semanticText, $this->parsedByLlm, $this->failure);
    }
}
