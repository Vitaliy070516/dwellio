<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Search\Enums\MatchSource;
use App\Modules\Search\Enums\SearchMode;

/** Результат одного еталонного запиту в `search:eval`. */
final readonly class QueryEvaluation
{
    /**
     * @param  list<int>  $ranked  id у порядку видачі (топ-k)
     * @param  array<value-of<MatchSource>, int>  $relevantHitsBySource  скільки релевантних із топ-k знайшла кожна гілка
     */
    public function __construct(
        public GoldenQuery $query,
        public int $relevantCount,
        public array $ranked,
        public float $recall,
        public float $reciprocalRank,
        public SearchMode $mode,
        public bool $parsedByLlm,
        public array $relevantHitsBySource,
    ) {}
}
