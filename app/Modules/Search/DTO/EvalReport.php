<?php

declare(strict_types=1);

namespace App\Modules\Search\DTO;

use App\Modules\Search\Enums\MatchSource;
use App\Modules\Search\Support\RankingMetrics;

/** Підсумок прогону golden set з одним набором параметрів пошуку. */
final readonly class EvalReport
{
    /**
     * @param  list<QueryEvaluation>  $queries
     * @param  list<string>  $skipped  id запитів без жодної релевантної квартири в БД (зламаний еталон або порожня БД)
     */
    public function __construct(
        public int $k,
        public int $rrfK,
        public int $candidates,
        public array $queries,
        public array $skipped = [],
    ) {}

    public function meanRecall(): float
    {
        return RankingMetrics::mean(array_map(static fn (QueryEvaluation $q): float => $q->recall, $this->queries));
    }

    public function mrr(): float
    {
        return RankingMetrics::mean(array_map(static fn (QueryEvaluation $q): float => $q->reciprocalRank, $this->queries));
    }

    /**
     * Внесок гілок: скільки релевантних результатів у топ-k знайшла кожна (один результат може мати кілька гілок).
     *
     * @return array<value-of<MatchSource>, int>
     */
    public function relevantHitsBySource(): array
    {
        $totals = [];
        foreach (MatchSource::cases() as $source) {
            $totals[$source->value] = array_sum(array_map(
                static fn (QueryEvaluation $q): int => $q->relevantHitsBySource[$source->value] ?? 0,
                $this->queries,
            ));
        }

        return $totals;
    }
}
