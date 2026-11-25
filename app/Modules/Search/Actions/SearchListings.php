<?php

declare(strict_types=1);

namespace App\Modules\Search\Actions;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\DTO\Embedding;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\Listings\Models\Listing;
use App\Modules\Search\DTO\ParsedQuery;
use App\Modules\Search\DTO\SearchFilters;
use App\Modules\Search\DTO\SearchResult;
use App\Modules\Search\Enums\SearchMode;
use App\Modules\Search\Support\ListingFilterSql;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Hybrid search: три незалежні ранжування (вектор, FTS, trigram) зливаються Reciprocal Rank Fusion,
 * а жорсткі фільтри діють у кожній гілці ДО ранжування.
 *
 * Чому RRF, а не сума скорів: косинусна відстань, ts_rank і similarity мають різні шкали,
 * їх не можна додавати. RRF використовує лише позиції: score = Σ 1 / (k + rank).
 *
 * Деградація замість помилки: немає LLM → запит іде як текст; немає ембедингів → FTS + trigram;
 * немає тексту → чисті SQL-фільтри.
 */
final readonly class SearchListings
{
    public function __construct(
        private ParseSearchQuery $parser,
        private EmbeddingClient $embeddings,
    ) {}

    public function handle(string $query, SearchFilters $explicit = new SearchFilters, ?int $limit = null): SearchResult
    {
        $limit = max(1, min($limit ?? config()->integer('dwellio.search.per_page'), 50));

        $parsed = $this->parser->handle($query);
        $parsed = new ParsedQuery(
            $parsed->filters->overriddenBy($explicit),
            $parsed->semanticText,
            $parsed->parsedByLlm,
        );

        $vector = $parsed->semanticText === '' ? null : $this->queryVector($parsed->semanticText);
        $tsQuery = self::tsQuery($parsed->semanticText);

        if ($parsed->semanticText === '' || ($vector === null && $tsQuery === null)) {
            return new SearchResult($this->filtersOnly($parsed->filters, $limit), SearchMode::FiltersOnly, $parsed);
        }

        $ids = $this->rankedIds($parsed, $vector, $tsQuery, $limit);

        return new SearchResult(
            $this->hydrate($ids),
            $vector === null ? SearchMode::TextOnly : SearchMode::Hybrid,
            $parsed,
        );
    }

    /**
     * Вектор запиту. Кешуємо в Redis (FinOps: повторні запити не платять за токени),
     * ключ містить модель, бо вектори різних моделей непорівнянні.
     *
     * @return list<float>|null null = провайдер недоступний, пошук деградує до тексту
     */
    private function queryVector(string $text): ?array
    {
        $key = sprintf('search:qvec:%s:%s', $this->embeddings->model(), hash('sha256', mb_strtolower($text)));

        /** @var list<float>|null $cached */
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $embedding = $this->embeddings->embed($text);
        } catch (EmbeddingFailedException|InvalidEmbeddingException $e) {
            Log::warning('Query embedding failed, falling back to text-only search.', ['error' => $e->getMessage()]);

            return null;
        }

        Cache::put($key, $embedding->vector, config()->integer('dwellio.search.query_embedding_ttl'));

        return $embedding->vector;
    }

    /**
     * Слова → OR-запит `to_tsquery`. Лишаємо тільки літери й цифри, тож інʼєкція в синтаксис tsquery неможлива.
     * Слова коротші за 3 символи (прийменники «з», «в») лише шумлять: словника стоп-слів у `simple` немає.
     */
    private static function tsQuery(string $text): ?string
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $matches);
        $words = array_values(array_unique($matches[0]));

        return $words === [] ? null : implode(' | ', $words);
    }

    /**
     * @param  list<float>|null  $vector
     * @return list<int>
     */
    private function rankedIds(ParsedQuery $parsed, ?array $vector, ?string $tsQuery, int $limit): array
    {
        $candidates = config()->integer('dwellio.search.candidates');
        [$where, $whereBindings] = ListingFilterSql::build($parsed->filters);

        // Вирази в FTS і trigram збігаються з виразами GIN-індексів із міграції listings.
        $doc = "(listings.title || ' ' || listings.description)";

        $ctes = [];
        $names = [];
        $bindings = [];

        if ($vector !== null) {
            $literal = (new Embedding($vector, $this->embeddings->model()))->toPgVector();
            $names[] = 'vec';
            $ctes[] = "vec AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY dist, id) AS rnk FROM (
                    SELECT listings.id, le.embedding <=> ?::vector AS dist
                    FROM listing_embeddings le JOIN listings ON listings.id = le.listing_id
                    WHERE le.embedding_model = ? AND {$where}
                    ORDER BY dist, listings.id LIMIT ?
                ) t
            )";
            $bindings = [...$bindings, $literal, $this->embeddings->model(), ...$whereBindings, $candidates];
        }

        if ($tsQuery !== null) {
            $names[] = 'fts';
            $names[] = 'trgm';
            $ctes[] = "fts AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY score DESC, id) AS rnk FROM (
                    SELECT listings.id, ts_rank_cd(to_tsvector('simple', {$doc}), q.tsq) AS score
                    FROM listings, to_tsquery('simple', ?) AS q(tsq)
                    WHERE {$where} AND to_tsvector('simple', {$doc}) @@ q.tsq
                    ORDER BY score DESC, listings.id LIMIT ?
                ) t
            )";
            $bindings = [...$bindings, $tsQuery, ...$whereBindings, $candidates];

            // Trigram ловить відмінки й одруківки («котом» ~ «кіт»), де точний FTS мовчить.
            $ctes[] = "trgm AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY score DESC, id) AS rnk FROM (
                    SELECT listings.id, word_similarity(?, {$doc}) AS score
                    FROM listings
                    WHERE {$where} AND word_similarity(?, {$doc}) >= 0.3
                    ORDER BY score DESC, listings.id LIMIT ?
                ) t
            )";
            $bindings = [...$bindings, $parsed->semanticText, ...$whereBindings, $parsed->semanticText, $candidates];
        }

        $union = implode(' UNION ALL ', array_map(
            static fn (string $name): string => "SELECT id, rnk FROM {$name}",
            $names,
        ));

        $sql = 'WITH '.implode(', ', $ctes)."
            SELECT id, SUM(1.0 / (? + rnk)) AS score
            FROM ({$union}) ranked
            GROUP BY id
            ORDER BY score DESC, id
            LIMIT ?";
        $bindings = [...$bindings, config()->integer('dwellio.search.rrf_k'), $limit];

        return $this->ids($sql, $bindings);
    }

    /** @return list<Listing> */
    private function filtersOnly(SearchFilters $filters, int $limit): array
    {
        [$where, $bindings] = ListingFilterSql::build($filters);

        return $this->hydrate($this->ids(
            "SELECT id FROM listings WHERE {$where} ORDER BY id DESC LIMIT ?",
            [...$bindings, $limit],
        ));
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<int>
     */
    private function ids(string $sql, array $bindings): array
    {
        $ids = [];
        foreach (DB::select($sql, $bindings) as $row) {
            if ($row instanceof stdClass && is_numeric($row->id ?? null)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * Один запит замість N+1; порядок видачі береться з RRF, а не з БД.
     *
     * @param  list<int>  $ids
     * @return list<Listing>
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = Listing::query()->whereIn('id', $ids)->get()->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            $listing = $byId->get($id);
            if ($listing instanceof Listing) {
                $ordered[] = $listing;
            }
        }

        return $ordered;
    }
}
