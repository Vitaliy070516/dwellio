<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\AiPricing;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Listings\Models\Listing;
use App\Modules\Search\Actions\EvaluateSearch;
use App\Modules\Search\Support\GoldenSet;

/**
 * Еталон пишеться руками, а за його цифрами крутимо параметри пошуку.
 * Тож і завантаження, і метрики мають бути перевірені не гірше за сам пошук.
 */

/** Тимчасові еталони цього процесу: прибираються після кожного тесту. */
function goldenPath(): string
{
    return sprintf('%s/dwellio-golden-%d-%s.json', sys_get_temp_dir(), getmypid(), bin2hex(random_bytes(4)));
}

/** @param  array<mixed>  $data */
function goldenFile(array $data): string
{
    $path = goldenPath();
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));

    return $path;
}

afterEach(function (): void {
    foreach (glob(sprintf('%s/dwellio-golden-%d-*.json', sys_get_temp_dir(), getmypid())) ?: [] as $file) {
        unlink($file);
    }
});

/** @return array<string, mixed> */
function goldenEntry(string $id, string $query, array $relevant): array
{
    return ['id' => $id, 'query' => $query, 'relevant' => $relevant];
}

it('loads the committed golden set', function (): void {
    $golden = GoldenSet::load(config()->string('dwellio.search.golden_set'));

    expect($golden)->toHaveCount(30)
        ->and(array_unique(array_map(static fn ($q): string => $q->id, $golden)))->toHaveCount(30);
});

it('parses relevance rules into filters and lowercased word groups', function (): void {
    [$query] = GoldenSet::fromArray(['queries' => [goldenEntry('q', 'тихо на Подолі', [
        'filters' => ['districts' => ['podilskyi'], 'pets_allowed' => true],
        'text' => [['Тих', 'спокійн']],
    ])]]);

    expect($query->relevantFilters->districts)->toBe([District::Podilskyi])
        ->and($query->relevantFilters->petsAllowed)->toBeTrue()
        ->and($query->textGroups)->toBe([['тих', 'спокійн']]);
});

it('rejects a broken golden set loudly', function (array $data, string $message): void {
    expect(fn () => GoldenSet::fromArray($data))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no queries' => [['queries' => []], 'queries'],
    'unknown district' => [['queries' => [goldenEntry('q', 'x', ['filters' => ['districts' => ['atlantis']]])]], 'districts'],
    'duplicate ids' => [['queries' => [goldenEntry('q', 'x', ['text' => [['a1']]]), goldenEntry('q', 'y', ['text' => [['b1']]])]], 'id'],
    'no criteria' => [['queries' => [goldenEntry('empty', 'x', ['filters' => []])]], 'no relevance criteria'],
    'empty word group' => [['queries' => [goldenEntry('q', 'x', ['text' => [[]]])]], 'text'],
]);

it('rejects unreadable and non-JSON files', function (): void {
    expect(fn () => GoldenSet::load('/nonexistent/golden.json'))->toThrow(InvalidArgumentException::class, 'not readable');

    $path = goldenPath();
    file_put_contents($path, '{"queries": [');
    expect(fn () => GoldenSet::load($path))->toThrow(InvalidArgumentException::class, 'not valid JSON');
});

it('measures recall and MRR against the relevance rule', function (): void {
    $quiet = Listing::factory()->active()->create(['title' => 'Тиха', 'description' => 'Тихий двір, можна з котом', 'pets_allowed' => true]);
    Listing::factory()->active()->create(['title' => 'Шумна', 'description' => 'Жвава вулиця, можна з котом', 'pets_allowed' => true]);
    // Без LLM-фільтрів (Fake повертає []) запит іде як текст: FTS знаходить «тихий».
    $golden = GoldenSet::fromArray(['queries' => [
        goldenEntry('quiet', 'тихий двір', ['text' => [['тих']]]),
        goldenEntry('nothing-relevant', 'пентхаус', ['text' => [['пентхаус']]]),
    ]]);

    $report = app(EvaluateSearch::class)->handle($golden, k: 10);

    expect($report->queries)->toHaveCount(1)
        ->and($report->skipped)->toBe(['nothing-relevant'])
        ->and($report->queries[0]->ranked[0])->toBe($quiet->id)
        ->and($report->queries[0]->relevantCount)->toBe(1)
        ->and($report->meanRecall())->toBe(1.0)
        ->and($report->mrr())->toBe(1.0)
        ->and($report->relevantHitsBySource()['fts'])->toBe(1);
});

it('catches a hard filter that excludes the relevant listing', function (): void {
    Listing::factory()->active()->create(['title' => 'Тиха', 'description' => 'тихий двір', 'district' => District::Podilskyi]);
    Listing::factory()->active()->create(['title' => 'Інша', 'description' => 'щось інше', 'district' => District::Obolonskyi]);
    // LLM «вигадує» Оболонь, якої в запиті немає, а grounding її прибирає → релевантна квартира знаходиться.
    fakeLlm()->respondWith(['districts' => ['obolonskyi'], 'semantic_query' => 'тихий двір']);

    $report = app(EvaluateSearch::class)->handle(GoldenSet::fromArray(['queries' => [
        goldenEntry('quiet-podil', 'тихий двір', ['filters' => ['districts' => ['podilskyi']]]),
    ]]));

    expect($report->mrr())->toBe(1.0);

    // Явний фільтр, що виключає релевантне, дає нуль: метрика справді ловить промахи.
    fakeLlm()->respondWith(['districts' => ['obolonskyi'], 'semantic_query' => 'щось']);
    $report = app(EvaluateSearch::class)->handle(GoldenSet::fromArray(['queries' => [
        goldenEntry('miss', 'щось на Оболоні', ['filters' => ['districts' => ['podilskyi']]]),
    ]]));

    expect($report->mrr())->toBe(0.0)->and($report->meanRecall())->toBe(0.0);
});

it('ignores drafts when computing the relevant set', function (): void {
    Listing::factory()->create(['status' => ListingStatus::Draft, 'description' => 'пентхаус']);

    $report = app(EvaluateSearch::class)->handle(GoldenSet::fromArray(['queries' => [
        goldenEntry('draft-only', 'пентхаус', ['text' => [['пентхаус']]]),
    ]]));

    expect($report->skipped)->toBe(['draft-only']);
});

it('sweeps the parameter grid and restores the config afterwards', function (): void {
    Listing::factory()->active()->create(['title' => 'Тиха', 'description' => 'Тихий двір']);
    $path = goldenFile(['queries' => [goldenEntry('quiet', 'тихий двір', ['text' => [['тих']]])]]);

    $this->artisan("search:eval --set={$path} --rrf-k=10,60 --candidates=20,50 --details")
        ->expectsOutputToContain('Recall@10')
        ->expectsOutputToContain('★')
        ->expectsOutputToContain('Per query')
        ->assertSuccessful();

    expect(config('dwellio.search.rrf_k'))->toBe(60)
        ->and(config('dwellio.search.candidates'))->toBe(50)
        ->and(config('dwellio.search.cache_stats'))->toBeTrue();
});

it('does not pollute the production cache hit rate', function (): void {
    Listing::factory()->active()->create(['title' => 'Тиха', 'description' => 'Тихий двір']);
    $path = goldenFile(['queries' => [goldenEntry('quiet', 'тихий двір', ['text' => [['тих']]])]]);

    $this->artisan("search:eval --set={$path} --rrf-k=10,60")->assertSuccessful();

    $this->artisan('search:cache-stats')->expectsOutputToContain('—')->assertSuccessful();
});

it('fails below the MRR threshold so it can gate a pipeline', function (): void {
    Listing::factory()->active()->create(['title' => 'Тиха', 'description' => 'Тихий двір']);
    $path = goldenFile(['queries' => [goldenEntry('miss', 'qqqq', ['text' => [['тих']]])]]);

    $this->artisan("search:eval --set={$path} --min-mrr=0.5")
        ->expectsOutputToContain('below the threshold')
        ->assertFailed();
});

it('rejects invalid options and a broken golden set', function (string $options): void {
    $this->artisan("search:eval {$options}")->assertExitCode(2);
})->with([
    'non-numeric grid' => ['--rrf-k=abc'],
    'zero candidates' => ['--candidates=0'],
    'k out of range' => ['--k=500'],
    'non-numeric threshold' => ['--min-mrr=abc'],
    'threshold above 1' => ['--min-mrr=1.5'],
    'missing file' => ['--set=/nonexistent.json'],
]);

it('refuses to spend a paid AI budget without --force', function (): void {
    config(['dwellio.ai.llm.driver' => 'laravel-ai', 'dwellio.ai.llm.provider' => 'anthropic', 'dwellio.ai.llm.model' => 'claude-haiku-5-5']);
    app()->forgetInstance(TokenBudget::class);
    app()->forgetInstance(AiPricing::class);
    $path = goldenFile(['queries' => [goldenEntry('quiet', 'тихий двір', ['text' => [['тих']]])]]);

    $this->artisan("search:eval --set={$path}")
        ->expectsOutputToContain('query_parsing')
        ->assertFailed();

    // Жодного виклику провайдера: відмова сталася до прогону.
    expect(app(TokenBudget::class)->usage(AiPurpose::QueryParsing)->calls)->toBe(0);
});

it('runs on free local models without --force', function (): void {
    config(['dwellio.ai.llm.driver' => 'laravel-ai', 'dwellio.ai.llm.provider' => 'ollama']);
    app()->forgetInstance(TokenBudget::class);
    app()->forgetInstance(AiPricing::class);

    expect(app(AiPricing::class)->isPaid(AiPurpose::QueryParsing))->toBeFalse()
        ->and(app(AiPricing::class)->isPaid(AiPurpose::Embeddings))->toBeFalse();
});
