<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use App\Modules\Search\Actions\ParseSearchQuery;
use App\Modules\Search\Actions\SearchListings;
use App\Modules\Search\Enums\SearchCache;
use App\Modules\Search\Services\SearchCacheStats;
use Carbon\CarbonImmutable;

/** @return array<string, array{int, int}> cache → [hits, misses] */
function cacheStats(?string $date = null): array
{
    $stats = [];
    foreach (app(SearchCacheStats::class)->forDate($date) as $rate) {
        $stats[$rate->cache->value] = [$rate->hits, $rate->misses];
    }

    return $stats;
}

it('serves cosmetic variants of a query from one cache entry', function (): void {
    fakeLlm()->respondWith(['semantic_query' => 'тиха квартира', 'pets_allowed' => true]);

    app(ParseSearchQuery::class)->handle('Тихо, можна з котом!');
    $second = app(ParseSearchQuery::class)->handle('тихо   можна з котом');

    expect(fakeLlm()->prompts())->toBe(['Тихо, можна з котом!'])
        ->and($second->filters->petsAllowed)->toBeTrue();
});

it('sends the original text to the LLM, not the normalized cache key', function (): void {
    app(ParseSearchQuery::class)->handle('«Тихо», біля метро!');

    expect(fakeLlm()->prompts())->toBe(['«Тихо», біля метро!']);
});

it('counts hits and misses for both search caches', function (): void {
    fakeLlm()->respondWith(['semantic_query' => 'тиха квартира']);

    app(SearchListings::class)->handle('тиха квартира');
    app(SearchListings::class)->handle('Тиха квартира!');
    app(SearchListings::class)->handle('тиха квартира');

    expect(cacheStats())->toBe([
        SearchCache::ParsedQuery->value => [2, 1],
        SearchCache::QueryEmbedding->value => [2, 1],
    ]);

    $parsed = app(SearchCacheStats::class)->forDate()[0];
    expect($parsed->rate())->toBe(2 / 3)->and($parsed->lookups())->toBe(3);
});

it('counts a failed LLM call as a miss and keeps missing until it succeeds', function (): void {
    fakeLlm()->failNextWith(new StructuredOutputFailedException('down'));

    app(ParseSearchQuery::class)->handle('тиха квартира');
    app(ParseSearchQuery::class)->handle('тиха квартира');

    expect(cacheStats()[SearchCache::ParsedQuery->value])->toBe([0, 2]);
});

it('does not touch the counters for a blank query', function (): void {
    app(ParseSearchQuery::class)->handle('   ');

    expect(cacheStats()[SearchCache::ParsedQuery->value])->toBe([0, 0])
        ->and(app(SearchCacheStats::class)->forDate()[0]->rate())->toBeNull();
});

it('can switch counting off', function (): void {
    config(['dwellio.search.cache_stats' => false]);

    app(ParseSearchQuery::class)->handle('тиха квартира');

    expect(cacheStats()[SearchCache::ParsedQuery->value])->toBe([0, 0]);
});

it('keeps counters per UTC day', function (): void {
    CarbonImmutable::setTestNow('2026-11-25 23:59:00');
    app(ParseSearchQuery::class)->handle('тиха квартира');

    CarbonImmutable::setTestNow('2026-11-26 00:01:00');
    app(ParseSearchQuery::class)->handle('тиха квартира');

    expect(cacheStats('2026-11-25')[SearchCache::ParsedQuery->value])->toBe([0, 1])
        ->and(cacheStats('2026-11-26')[SearchCache::ParsedQuery->value])->toBe([1, 0]);

    CarbonImmutable::setTestNow();
});

it('prints the hit rate table', function (): void {
    app(ParseSearchQuery::class)->handle('тиха квартира');
    app(ParseSearchQuery::class)->handle('тиха квартира');

    $this->artisan('search:cache-stats')
        ->expectsOutputToContain('Search cache hit rate for')
        // Один рядок таблиці зараховується лише одному очікуванню, тож перевіряємо hit rate окремо від назви.
        ->expectsOutputToContain('50.0%')
        ->assertSuccessful();
});

it('rejects a malformed date', function (): void {
    $this->artisan('search:cache-stats --date=26.11.2026')->assertExitCode(2);
});
