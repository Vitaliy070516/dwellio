<?php

declare(strict_types=1);

use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\AI\Exceptions\StructuredOutputFailedException;
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
        ->assertJsonPath('data.0.match.ranks.vector', fn (mixed $rank): bool => is_int($rank))
        ->assertJsonStructure([
            'data' => [['id', 'title', 'price_uah', 'match' => ['matched_by', 'ranks', 'score']]],
            'meta' => ['mode', 'filters', 'semantic_query', 'degraded' => ['query_parsing', 'embeddings'], 'count'],
        ])
        ->assertJsonPath('meta.degraded', ['query_parsing' => null, 'embeddings' => null]);
});

it('lists the matching branches in a stable order', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Тиха', 'description' => 'квартира з котом']);
    app(EmbedListing::class)->handle($listing);
    fakeLlm()->respondWith(['semantic_query' => 'квартира котом']);

    $matchedBy = $this->getJson('/api/search?q='.urlencode('квартира з котом'))->assertOk()->json('data.0.match.matched_by');

    // vector завжди знаходить «найближчого»; FTS — за словом «квартира»; порядок — як у MatchSource.
    expect($matchedBy)->toBe(['vector', 'fts', 'trigram']);
});

it('reports degraded mode to the client', function (): void {
    Listing::factory()->create(['status' => ListingStatus::Active, 'rooms' => 2]);

    $this->getJson('/api/search?rooms[]=2')
        ->assertOk()
        ->assertJsonPath('meta.mode', 'filters_only')
        ->assertJsonPath('data.0.match', null)
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

it('keeps searching without the LLM once the query-parsing budget is exhausted', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Тиха', 'description' => 'квартира з котом']);
    app(EmbedListing::class)->handle($listing);
    exhaustTokenBudget(AiPurpose::QueryParsing);

    $this->getJson('/api/search?q='.urlencode('квартира з котом'))
        ->assertOk()
        ->assertJsonPath('meta.parsed_by_llm', false)
        ->assertJsonPath('meta.degraded.query_parsing', 'budget_exceeded')
        ->assertJsonPath('meta.degraded.embeddings', null)
        ->assertJsonPath('data.0.id', $listing->id);

    expect(fakeLlm()->prompts())->toBe([]);
});

it('degrades to text-only search once the embeddings budget is exhausted', function (): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Тиха', 'description' => 'квартира з котом']);
    exhaustTokenBudget(AiPurpose::Embeddings);
    fakeLlm()->respondWith(['semantic_query' => 'квартира котом']);

    $this->getJson('/api/search?q='.urlencode('квартира з котом'))
        ->assertOk()
        ->assertJsonPath('meta.mode', 'text_only')
        ->assertJsonPath('meta.degraded.query_parsing', null)
        ->assertJsonPath('meta.degraded.embeddings', 'budget_exceeded')
        ->assertJsonPath('data.0.id', $listing->id);

    expect(fakeEmbeddingClient()->embeddedTexts())->toBe([]);
});

it('rate-limits search per IP', function (): void {
    config(['dwellio.search.rate_limit.per_minute_per_ip' => 2]);

    $this->getJson('/api/search?rooms[]=2')->assertOk();
    $this->getJson('/api/search?rooms[]=2')->assertOk();
    $this->getJson('/api/search?rooms[]=2')->assertTooManyRequests();

    // Інший IP має власний лічильник.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/search?rooms[]=2')->assertOk();
});

/*
 * `mode` каже, ЯК виконано пошук; `degraded` — ЧОМУ AI-крок не відпрацював.
 * Фронт показує «бюджет вичерпано» інакше, ніж «AI недоступний», тож причина — частина контракту.
 */
it('tells the client why each AI step degraded', function (string $failure, ?string $parsing, ?string $embeddings, string $mode): void {
    $listing = Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Тиха', 'description' => 'квартира з котом']);
    app(EmbedListing::class)->handle($listing);
    fakeLlm()->respondWith(['semantic_query' => 'квартира котом']);

    match ($failure) {
        'llm-down' => fakeLlm()->failNextWith(new StructuredOutputFailedException('Connection refused')),
        'llm-garbage' => fakeLlm()->respondWith(['price_max' => 'дешево']),
        'embeddings-down' => fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('timeout')),
        'embeddings-invalid' => fakeEmbeddingClient()->failNextWith(new InvalidEmbeddingException('dimensions')),
        'budgets' => array_map(exhaustTokenBudget(...), [AiPurpose::QueryParsing, AiPurpose::Embeddings]),
    };

    $this->getJson('/api/search?q='.urlencode('квартира з котом'))
        ->assertOk()
        ->assertJsonPath('meta.mode', $mode)
        ->assertJsonPath('meta.degraded.query_parsing', $parsing)
        ->assertJsonPath('meta.degraded.embeddings', $embeddings)
        ->assertJsonPath('data.0.id', $listing->id);
})->with([
    'LLM down' => ['llm-down', 'provider_unavailable', null, 'hybrid'],
    'LLM returns garbage' => ['llm-garbage', 'invalid_output', null, 'hybrid'],
    'embeddings down' => ['embeddings-down', null, 'provider_unavailable', 'text_only'],
    'embedding is invalid' => ['embeddings-invalid', null, 'invalid_output', 'text_only'],
    'both budgets exhausted' => ['budgets', 'budget_exceeded', 'budget_exceeded', 'text_only'],
]);

it('does not report degradation for a cached parse even when the budget is exhausted later', function (): void {
    Listing::factory()->create(['status' => ListingStatus::Active, 'title' => 'Тиха', 'description' => 'квартира з котом']);
    fakeLlm()->respondWith(['semantic_query' => 'квартира котом']);
    $this->getJson('/api/search?q='.urlencode('квартира з котом'))->assertOk();

    exhaustTokenBudget(AiPurpose::QueryParsing);

    // Кеш розбору — ще й страховка: повторний запит не потребує LLM, тож і деградації немає.
    $this->getJson('/api/search?q='.urlencode('квартира з котом'))
        ->assertOk()
        ->assertJsonPath('meta.parsed_by_llm', true)
        ->assertJsonPath('meta.degraded.query_parsing', null);
});

it('reports no degradation when no AI step was needed', function (): void {
    Listing::factory()->create(['status' => ListingStatus::Active, 'rooms' => 2]);
    exhaustTokenBudget(AiPurpose::QueryParsing);
    exhaustTokenBudget(AiPurpose::Embeddings);

    // Лише фільтри, без тексту: AI не викликався, тож і «бюджет вичерпано» показувати нема чого.
    $this->getJson('/api/search?rooms[]=2')
        ->assertOk()
        ->assertJsonPath('meta.mode', 'filters_only')
        ->assertJsonPath('meta.degraded', ['query_parsing' => null, 'embeddings' => null]);
});
