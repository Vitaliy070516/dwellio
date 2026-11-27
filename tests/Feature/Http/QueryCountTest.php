<?php

declare(strict_types=1);

use App\Modules\Listings\Actions\EmbedListing;
use App\Modules\Listings\Models\Listing;
use Illuminate\Support\Facades\DB;

/*
 * Сторож N+1 (День 13): кількість SQL-запитів ендпоінта не залежить від кількості квартир у відповіді.
 * Порівнюємо 2 і 12 результатів, а не фіксоване число: тест не ламається від нового службового запиту,
 * але червоніє, щойно хтось додасть ліниве відношення в Resource чи запит у цикл.
 */

/** @param  Closure(): mixed  $request */
function countQueries(Closure $request): int
{
    $count = 0;
    DB::listen(static function () use (&$count): void {
        $count++;
    });

    $request();

    return $count;
}

/** @param  array<string, mixed>  $attributes */
function makeSearchableListings(int $count, array $attributes = []): void
{
    $attributes = ['description' => 'тиха квартира з котом', ...$attributes];

    foreach (Listing::factory()->active()->count($count)->create($attributes) as $listing) {
        app(EmbedListing::class)->handle($listing);
    }
}

it('serves the listings index with a constant number of queries', function (): void {
    makeSearchableListings(2);
    $few = countQueries(fn () => $this->getJson('/api/listings')->assertOk()->assertJsonCount(2, 'data'));

    makeSearchableListings(10);
    $many = countQueries(fn () => $this->getJson('/api/listings')->assertOk()->assertJsonCount(12, 'data'));

    expect($many)->toBe($few);
});

it('serves a hybrid search with a constant number of queries', function (): void {
    fakeLlm()->respondWith(['semantic_query' => 'тиха квартира котом']);

    makeSearchableListings(2);
    $few = countQueries(fn () => $this->getJson('/api/search?q='.urlencode('тиха з котом'))
        ->assertOk()->assertJsonPath('meta.mode', 'hybrid')->assertJsonCount(2, 'data'));

    makeSearchableListings(10);
    $many = countQueries(fn () => $this->getJson('/api/search?q='.urlencode('тиха з котом'))
        ->assertOk()->assertJsonCount(12, 'data'));

    // Один RRF-запит + один whereIn для гідрації; кеш розбору й вектора — у Redis/array, не в SQL.
    expect($many)->toBe($few)->toBeLessThanOrEqual(3);
});

it('serves a filters-only search with a constant number of queries', function (): void {
    // Квартири явно проходять фільтр: з випадковими pets_allowed/rooms із фабрики перший пошук іноді
    // нічого не знаходив, гідрації не було (1 запит замість 2) — і тест падав через раз.
    $matching = ['pets_allowed' => true, 'rooms' => 2];
    $url = '/api/search?pets_allowed=1&rooms[]=1&rooms[]=2&rooms[]=3&rooms[]=4&rooms[]=5';

    makeSearchableListings(2, $matching);
    $few = countQueries(fn () => $this->getJson($url)->assertOk()->assertJsonCount(2, 'data'));

    makeSearchableListings(10, $matching);
    $many = countQueries(fn () => $this->getJson($url)->assertOk()->assertJsonCount(12, 'data'));

    expect($many)->toBe($few);
});
