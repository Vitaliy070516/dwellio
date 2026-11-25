<?php

declare(strict_types=1);

use App\Modules\Listings\Actions\EmbedListing;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Listings\Models\Listing;

it('returns listings with search meta', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Тиха', 'description' => 'квартира з котом']);
    app(EmbedListing::class)->handle($listing);
    fakeLlm()->respondWith(['semantic_query' => 'кіт квартира котом']);

    $this->getJson('/api/search?q='.urlencode('з котом'))
        ->assertOk()
        ->assertJsonPath('meta.mode', 'hybrid')
        ->assertJsonPath('meta.parsed_by_llm', true)
        ->assertJsonPath('data.0.id', $listing->id)
        ->assertJsonStructure(['data' => [['id', 'title', 'price_uah']], 'meta' => ['mode', 'filters', 'semantic_query', 'count']]);
});

it('reports degraded mode to the client', function (): void {
    Listing::factory()->create(['status' => ListingStatus::Active, 'rooms' => 2]);

    $this->getJson('/api/search?rooms[]=2')
        ->assertOk()
        ->assertJsonPath('meta.mode', 'filters_only')
        ->assertJsonCount(1, 'data');
});

it('rejects a request with neither a query nor a filter', function (): void {
    $this->getJson('/api/search')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/search?q=%20%20')->assertUnprocessable();
});

it('validates input', function (string $query): void {
    $this->getJson('/api/search?'.$query)->assertUnprocessable();
})->with([
    'too long query' => [fn () => 'q='.str_repeat('а', 301)],
    'bad district' => ['q=x&districts[]=atlantis'],
    'bad limit' => ['q=x&limit=500'],
    'negative price' => ['q=x&price_max=-1'],
]);
