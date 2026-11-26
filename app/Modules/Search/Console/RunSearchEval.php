<?php

declare(strict_types=1);

namespace App\Modules\Search\Console;

use App\Modules\AI\Contracts\AiPricing;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\Search\Actions\EvaluateSearch;
use App\Modules\Search\DTO\EvalReport;
use App\Modules\Search\DTO\QueryEvaluation;
use App\Modules\Search\Enums\MatchSource;
use App\Modules\Search\Support\GoldenSet;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Офлайн-оцінка пошуку на golden set: `search:eval --rrf-k=10,30,60 --candidates=20,50,100`.
 *
 * Перший прогін платить за розбір і ембединги (з Ollama — $0), решта точок сітки беруть їх із кешу.
 * Метрики мають сенс лише з реальними ембедингами: Fake-вектори не семантичні.
 */
final class RunSearchEval extends Command
{
    protected $signature = 'search:eval
        {--set= : Шлях до golden set (за замовчуванням dwellio.search.golden_set)}
        {--k=10 : Глибина оцінки (Recall@k, MRR@k), 1–50}
        {--rrf-k= : Значення rrf_k через кому; за замовчуванням поточне з конфігу}
        {--candidates= : Значення candidates через кому; за замовчуванням поточне з конфігу}
        {--details : Таблиця по кожному запиту для найкращої точки сітки}
        {--min-mrr= : Завершитися з помилкою, якщо MRR найкращої точки нижчий (регресійний поріг), 0–1}
        {--force : Дозволити прогін на платних моделях (витрачає спільний денний бюджет AI)}';

    protected $description = 'Evaluate hybrid search on the golden set (Recall@k, MRR) and sweep rrf_k / candidates';

    public function handle(EvaluateSearch $evaluate, AiPricing $pricing): int
    {
        $k = filter_var($this->option('k'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        $rrfKs = $this->intList('rrf-k', config()->integer('dwellio.search.rrf_k'));
        $candidates = $this->intList('candidates', config()->integer('dwellio.search.candidates'));

        $minMrrOption = $this->option('min-mrr');
        $minMrr = is_string($minMrrOption) && $minMrrOption !== ''
            ? filter_var($minMrrOption, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 1]])
            : null;

        if ($k === false || $rrfKs === null || $candidates === null || $minMrr === false) {
            $this->error('Use --k=1..50, --min-mrr=0..1 and comma-separated positive integers for --rrf-k / --candidates.');

            return self::INVALID;
        }

        // Eval іде через повний конвеєр: на Anthropic/Voyage кожен некешований запит — справжні гроші
        // зі СПІЛЬНОГО з користувачами денного бюджету. Мовчки палити його службовою командою не можна.
        $paid = array_values(array_filter(
            [AiPurpose::QueryParsing, AiPurpose::Embeddings],
            static fn (AiPurpose $purpose): bool => $pricing->isPaid($purpose),
        ));
        if ($paid !== [] && ! $this->option('force')) {
            $this->error(sprintf(
                'Paid models are configured for: %s. The first pass makes up to 2 calls per golden query and spends the shared daily AI budget. Re-run with --force to accept.',
                implode(', ', array_map(static fn (AiPurpose $p): string => $p->value, $paid)),
            ));

            return self::FAILURE;
        }

        $path = $this->option('set');
        try {
            $golden = GoldenSet::load(is_string($path) && $path !== '' ? $path : config()->string('dwellio.search.golden_set'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $original = ['rrf_k' => config('dwellio.search.rrf_k'), 'candidates' => config('dwellio.search.candidates')];
        $statsEnabled = config('dwellio.search.cache_stats');
        $this->line(sprintf(
            'Golden set: %d queries · k=%d · embeddings: %s · LLM: %s',
            count($golden),
            $k,
            config()->string('dwellio.ai.embeddings.model'),
            config()->string('dwellio.ai.llm.driver') === 'fake' ? 'fake' : config()->string('dwellio.ai.llm.model'),
        ));

        // Штучні звернення до кешу не мають потрапляти в реальний hit rate.
        config(['dwellio.search.cache_stats' => false]);

        /** @var list<EvalReport> $reports */
        $reports = [];
        try {
            foreach ($rrfKs as $rrfK) {
                foreach ($candidates as $candidateCount) {
                    config(['dwellio.search.rrf_k' => $rrfK, 'dwellio.search.candidates' => $candidateCount]);
                    $reports[] = $evaluate->handle($golden, $k);
                }
            }
        } finally {
            config(['dwellio.search.rrf_k' => $original['rrf_k'], 'dwellio.search.candidates' => $original['candidates'], 'dwellio.search.cache_stats' => $statsEnabled]);
        }

        $best = $this->best($reports, $original);
        $this->renderGrid($reports, $best, $original, $k);

        if ($best->skipped !== []) {
            $this->warn('Skipped (no relevant listings in DB — re-seed or fix the golden set): '.implode(', ', $best->skipped));
        }

        if ($this->option('details')) {
            $this->renderDetails($best, $k);
        }

        if ($minMrr !== null && $best->mrr() < $minMrr) {
            $this->error(sprintf('MRR %.3f is below the threshold %.3f.', $best->mrr(), $minMrr));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Найкраща точка: MRR, потім Recall; за рівності — поточний конфіг (не міняємо параметри заради шуму).
     *
     * @param  non-empty-list<EvalReport>  $reports
     * @param  array{rrf_k: mixed, candidates: mixed}  $current
     */
    private function best(array $reports, array $current): EvalReport
    {
        $best = $reports[0];
        foreach ($reports as $report) {
            $delta = [round($report->mrr() - $best->mrr(), 4), round($report->meanRecall() - $best->meanRecall(), 4)];
            $isCurrent = $report->rrfK === $current['rrf_k'] && $report->candidates === $current['candidates'];

            if ($delta > [0.0, 0.0] || ($delta === [0.0, 0.0] && $isCurrent)) {
                $best = $report;
            }
        }

        return $best;
    }

    /**
     * @param  list<EvalReport>  $reports
     * @param  array{rrf_k: mixed, candidates: mixed}  $current
     */
    private function renderGrid(array $reports, EvalReport $best, array $current, int $k): void
    {
        $rows = [];
        foreach ($reports as $report) {
            $marks = ($report === $best ? '★' : '').($report->rrfK === $current['rrf_k'] && $report->candidates === $current['candidates'] ? ' (config)' : '');
            $rows[] = [
                $report->rrfK,
                $report->candidates,
                sprintf('%.3f', $report->meanRecall()),
                sprintf('%.3f', $report->mrr()),
                ...array_values($report->relevantHitsBySource()),
                trim($marks),
            ];
        }

        $this->table(
            ['rrf_k', 'candidates', "Recall@{$k}", "MRR@{$k}", ...array_map(static fn (MatchSource $s): string => "hits:{$s->value}", MatchSource::cases()), ''],
            $rows,
        );
    }

    private function renderDetails(EvalReport $report, int $k): void
    {
        $this->info(sprintf('Per query (rrf_k=%d, candidates=%d)', $report->rrfK, $report->candidates));

        $this->table(
            ['id', 'mode', 'llm', 'relevant', "R@{$k}", 'RR', 'query'],
            array_map(static fn (QueryEvaluation $q): array => [
                $q->query->id,
                $q->mode->value,
                $q->parsedByLlm ? 'yes' : 'no',
                $q->relevantCount,
                sprintf('%.2f', $q->recall),
                sprintf('%.2f', $q->reciprocalRank),
                mb_strimwidth($q->query->query, 0, 48, '…'),
            ], $report->queries),
        );
    }

    /** @return non-empty-list<int>|null null = невалідний ввід */
    private function intList(string $option, int $default): ?array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [$default];
        }

        $values = [];
        foreach (explode(',', $raw) as $part) {
            $value = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value === false) {
                return null;
            }
            $values[] = $value;
        }

        return array_values(array_unique($values));
    }
}
