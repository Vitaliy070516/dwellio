<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use App\Modules\Listings\Actions\EmbedListing;
use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Listings\Models\Listing;
use App\Modules\Search\Actions\SearchListings;
use App\Modules\Search\DTO\SearchFilters;
use App\Modules\Search\Enums\SearchMode;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $attributes
 */
function indexed(array $attributes = []): Listing
{
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, ...$attributes]);
    app(EmbedListing::class)->handle($listing);

    return $listing;
}

/**
 * @return list<int>
 */
function searchIds(string $query, SearchFilters $explicit = new SearchFilters): array
{
    return array_map(
        static fn (Listing $l): int => $l->id,
        app(SearchListings::class)->handle($query, $explicit)->listings,
    );
}

it('ranks by vector similarity in hybrid mode', function (): void {
    $target = indexed(['title' => 'Alpha', 'description' => 'Перша квартира']);
    indexed(['title' => 'Beta', 'description' => 'Друга квартира']);

    // Fake-вектор детермінований за текстом: точний збіг із джерелом ембедингу = відстань 0.
    fakeLlm()->respondWith(['semantic_query' => $target->embeddingSource()]);

    $result = app(SearchListings::class)->handle('будь-що');

    expect($result->mode)->toBe(SearchMode::Hybrid)
        ->and($result->listings[0]->id)->toBe($target->id);
});

it('degrades to text-only search when the embedding provider is down', function (): void {
    $match = indexed(['title' => 'Квартира з балконом', 'description' => 'Дозволено проживання з котом']);
    indexed(['title' => 'Інша', 'description' => 'Без тварин, тихий двір']);

    fakeLlm()->respondWith(['semantic_query' => 'котом']);
    fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('Ollama is down'));

    $result = app(SearchListings::class)->handle('з котом');

    expect($result->mode)->toBe(SearchMode::TextOnly)
        ->and($result->listings[0]->id)->toBe($match->id);
});

it('survives a total AI outage: LLM and embeddings both down', function (): void {
    $match = indexed(['title' => 'Світла студія', 'description' => 'Тихий двір, свіжий ремонт']);

    fakeLlm()->failNextWith(new StructuredOutputFailedException('down'));
    fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('down'));

    $result = app(SearchListings::class)->handle('тихий двір');

    expect($result->mode)->toBe(SearchMode::TextOnly)
        ->and($result->query->parsedByLlm)->toBeFalse()
        ->and($result->listings[0]->id)->toBe($match->id);
});

it('applies hard filters before ranking in every branch', function (): void {
    $cheap = indexed(['title' => 'Тиха', 'description' => 'Тиха квартира', 'price_uah' => 10000, 'rooms' => 1]);
    $pricey = indexed(['title' => 'Тиха дорога', 'description' => 'Тиха квартира', 'price_uah' => 40000, 'rooms' => 1]);

    fakeLlm()->respondWith(['price_max' => 20000, 'semantic_query' => 'тиха квартира']);

    $ids = searchIds('тиха квартира до 20 тисяч');

    expect($ids)->toContain($cheap->id)->not->toContain($pricey->id);
});

it('lets explicit filters override what the LLM guessed', function (): void {
    $a = indexed(['title' => 'Тиха', 'description' => 'квартира', 'price_uah' => 25000]);

    fakeLlm()->respondWith(['price_max' => 10000, 'semantic_query' => 'квартира']);

    expect(searchIds('квартира', new SearchFilters(priceMax: 30000)))->toContain($a->id);
});

it('falls back to pure SQL filters when there is no text to rank', function (): void {
    $match = indexed(['rooms' => 2, 'district' => District::Podilskyi]);
    indexed(['rooms' => 3, 'district' => District::Podilskyi]);
    indexed(['rooms' => 2, 'district' => District::Obolonskyi]);

    $result = app(SearchListings::class)->handle('', new SearchFilters(rooms: [2], districts: [District::Podilskyi]));

    expect($result->mode)->toBe(SearchMode::FiltersOnly)
        ->and(array_map(static fn (Listing $l): int => $l->id, $result->listings))->toBe([$match->id]);
});

it('filters by pets, furnished and metro distance', function (): void {
    $ok = indexed(['pets_allowed' => true, 'furnished' => true, 'metro_distance_m' => 300]);
    indexed(['pets_allowed' => false, 'furnished' => true, 'metro_distance_m' => 300]);
    indexed(['pets_allowed' => true, 'furnished' => true, 'metro_distance_m' => 900]);
    indexed(['pets_allowed' => true, 'furnished' => true, 'metro_distance_m' => null]);

    $result = app(SearchListings::class)->handle('', new SearchFilters(petsAllowed: true, furnished: true, maxMetroDistanceM: 500));

    expect(array_map(static fn (Listing $l): int => $l->id, $result->listings))->toBe([$ok->id]);
});

it('returns an empty list, not an error, when nothing matches', function (): void {
    indexed(['price_uah' => 30000]);

    fakeLlm()->respondWith(['price_max' => 1000, 'semantic_query' => 'квартира']);

    $result = app(SearchListings::class)->handle('квартира до 1000');

    expect($result->listings)->toBe([]);
});

it('never returns drafts, archived or soft-deleted listings', function (): void {
    $visible = indexed(['title' => 'Видима', 'description' => 'унікальнеслово']);
    $draft = Listing::factory()->create(['status' => ListingStatus::Draft, 'description' => 'унікальнеслово']);
    $archived = Listing::factory()->create(['status' => ListingStatus::Archived, 'description' => 'унікальнеслово']);
    $deleted = indexed(['description' => 'унікальнеслово']);
    $deleted->delete();

    fakeLlm()->respondWith(['semantic_query' => 'унікальнеслово']);

    $ids = searchIds('унікальнеслово');

    expect($ids)->toBe([$visible->id])->not->toContain($draft->id, $archived->id, $deleted->id);
});

it('ignores embeddings of another model', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Zzz', 'description' => 'Yyy']);
    app(EmbedListing::class)->handle($listing);
    // Імітуємо перехід на нову модель: старий вектор лишається, але не має брати участі в пошуку.
    DB::table('listing_embeddings')->update(['embedding_model' => 'legacy-model']);

    // Текст запиту не перетинається з квартирою ні в FTS, ні в trigram: вектор-гілка (яка завжди
    // повертає «найближчого») була б єдиним способом її знайти, якби не фільтр за моделлю.
    fakeLlm()->respondWith(['semantic_query' => 'qqqq']);
    $result = app(SearchListings::class)->handle('qqqq');

    expect($result->listings)->toBe([]);
});

it('treats SQL and tsquery metacharacters in the query as plain text', function (): void {
    indexed(['title' => 'Тиха', 'description' => 'квартира']);

    fakeLlm()->respondWith(['semantic_query' => "'; DROP TABLE listings; -- | & ! ( ) :*"]);

    expect(fn () => searchIds('x'))->not->toThrow(Throwable::class)
        ->and(Listing::query()->count())->toBe(1);
});

it('respects the limit', function (): void {
    foreach (range(1, 5) as $i) {
        indexed(['description' => 'спільне слово номер'.$i]);
    }

    fakeLlm()->respondWith(['semantic_query' => 'спільне слово']);

    $result = app(SearchListings::class)->handle('спільне слово', limit: 3);

    expect($result->listings)->toHaveCount(3);
});

it('caches the query embedding so a repeated search does not pay twice', function (): void {
    indexed(['title' => 'Тиха', 'description' => 'квартира']);
    fakeLlm()->respondWith(['semantic_query' => 'тиха квартира']);
    $before = count(fakeEmbeddingClient()->embeddedTexts());

    searchIds('тиха квартира');
    searchIds('тиха квартира');

    expect(count(fakeEmbeddingClient()->embeddedTexts()) - $before)->toBe(1);
});
