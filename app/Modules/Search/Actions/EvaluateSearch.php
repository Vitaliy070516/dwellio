<?php

declare(strict_types=1);

namespace App\Modules\Search\Actions;

use App\Modules\Search\DTO\EvalReport;
use App\Modules\Search\DTO\GoldenQuery;
use App\Modules\Search\DTO\QueryEvaluation;
use App\Modules\Search\Support\ListingFilterSql;
use App\Modules\Search\Support\RankingMetrics;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Офлайн-оцінка якості пошуку: проганяє golden set через ПОВНИЙ конвеєр (LLM-розбір → hybrid → RRF)
 * і порівнює видачу з правилом релевантності кожного запиту.
 *
 * Параметри (rrf_k, candidates) читаються з конфігу, тож команда може перебрати сітку значень,
 * а розбір запиту й ембединг між прогонами беруться з кешу — платимо лише за перший.
 */
final readonly class EvaluateSearch
{
    public function __construct(private SearchListings $search) {}

    /**
     * @param  list<GoldenQuery>  $golden
     */
    public function handle(array $golden, int $k = 10): EvalReport
    {
        $evaluations = [];
        $skipped = [];

        foreach ($golden as $query) {
            $relevant = $this->relevantIds($query);
            if ($relevant === []) {
                $skipped[] = $query->id;

                continue;
            }

            $result = $this->search->handle($query->query, limit: $k);
            $ranked = array_map(static fn ($listing): int => $listing->id, $result->listings);

            $relevantSet = array_flip($relevant);
            $bySource = [];
            foreach ($ranked as $id) {
                if (! isset($relevantSet[$id])) {
                    continue;
                }

                foreach (($result->matches[$id] ?? null)?->matchedBy() ?? [] as $source) {
                    $bySource[$source->value] = ($bySource[$source->value] ?? 0) + 1;
                }
            }

            $evaluations[] = new QueryEvaluation(
                $query,
                count($relevant),
                $ranked,
                RankingMetrics::recallAt($ranked, $relevant, $k),
                RankingMetrics::reciprocalRank($ranked, $relevant, $k),
                $result->mode,
                $result->query->parsedByLlm,
                $bySource,
            );
        }

        return new EvalReport(
            $k,
            config()->integer('dwellio.search.rrf_k'),
            config()->integer('dwellio.search.candidates'),
            $evaluations,
            $skipped,
        );
    }

    /**
     * Релевантні = активні квартири, що проходять фільтри еталона і містять хоч одне слово з КОЖНОЇ групи.
     * Фільтри — той самий ListingFilterSql, що й у пошуку: еталон і пошук однаково розуміють «до 20 000».
     *
     * @return list<int>
     */
    private function relevantIds(GoldenQuery $query): array
    {
        [$where, $bindings] = ListingFilterSql::build($query->relevantFilters);
        $doc = "(listings.title || ' ' || listings.description)";

        foreach ($query->textGroups as $group) {
            $where .= ' AND ('.implode(' OR ', array_fill(0, count($group), "{$doc} ILIKE ?")).')';
            foreach ($group as $word) {
                $bindings[] = '%'.addcslashes($word, '%_\\').'%';
            }
        }

        $ids = [];
        foreach (DB::select("SELECT listings.id FROM listings WHERE {$where}", $bindings) as $row) {
            if ($row instanceof stdClass && is_numeric($row->id ?? null)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }
}
