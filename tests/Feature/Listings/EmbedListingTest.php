<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\Listings\Actions\EmbedListing;
use App\Modules\Listings\Enums\EmbedOutcome;
use App\Modules\Listings\Models\Listing;
use Illuminate\Support\Facades\DB;

function fakeEmbeddingClient(): FakeEmbeddingClient
{
    $client = app(EmbeddingClient::class);
    assert($client instanceof FakeEmbeddingClient);

    return $client;
}

it('embeds an active listing and stores model, hash and a 1024-dim vector', function (): void {
    $listing = Listing::factory()->active()->create();

    $outcome = app(EmbedListing::class)->handle($listing);

    expect($outcome)->toBe(EmbedOutcome::Embedded);
    $this->assertDatabaseHas('listing_embeddings', [
        'listing_id' => $listing->id,
        'embedding_model' => 'fake-embedding',
        'content_hash' => hash('sha256', $listing->embeddingSource()),
    ]);
    expect(DB::selectOne('SELECT vector_dims(embedding) AS dims FROM listing_embeddings')->dims)->toBe(1024);
});

it('does not pay for the provider again when the text is unchanged', function (): void {
    $listing = Listing::factory()->active()->create();
    $action = app(EmbedListing::class);

    $action->handle($listing);
    $second = $action->handle($listing);

    expect($second)->toBe(EmbedOutcome::Unchanged)
        ->and(fakeEmbeddingClient()->embeddedTexts())->toHaveCount(1);
    $this->assertDatabaseCount('listing_embeddings', 1);
});

it('does not re-embed when only non-text fields change (price, rooms)', function (): void {
    $listing = Listing::factory()->active()->create(['price_uah' => 10000]);
    $action = app(EmbedListing::class);
    $action->handle($listing);

    $listing->update(['price_uah' => 25000, 'rooms' => 3]);

    expect($action->handle($listing->refresh()))->toBe(EmbedOutcome::Unchanged)
        ->and(fakeEmbeddingClient()->embeddedTexts())->toHaveCount(1);
});

it('re-embeds and updates the same row when the text changes', function (): void {
    $listing = Listing::factory()->active()->create();
    $action = app(EmbedListing::class);
    $action->handle($listing);
    $oldHash = DB::table('listing_embeddings')->value('content_hash');

    $listing->update(['description' => 'Зовсім новий опис: тихо, з видом на парк, можна з котом.']);
    $outcome = $action->handle($listing->refresh());

    expect($outcome)->toBe(EmbedOutcome::Embedded)
        ->and(fakeEmbeddingClient()->embeddedTexts())->toHaveCount(2)
        ->and(DB::table('listing_embeddings')->value('content_hash'))
        ->not->toBe($oldHash)
        ->toBe(hash('sha256', $listing->embeddingSource()));
    $this->assertDatabaseCount('listing_embeddings', 1);
});

it('skips drafts and archived listings without calling the provider', function (string $state): void {
    $listing = Listing::factory()->{$state}()->create();

    expect(app(EmbedListing::class)->handle($listing))->toBe(EmbedOutcome::Skipped)
        ->and(fakeEmbeddingClient()->embeddedTexts())->toBe([]);
    $this->assertDatabaseCount('listing_embeddings', 0);
})->with(['draft', 'archived']);

it('stores nothing when the provider fails, and recovers on the next attempt', function (): void {
    $listing = Listing::factory()->active()->create();
    fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('provider down'));
    $action = app(EmbedListing::class);

    expect(fn () => $action->handle($listing))->toThrow(EmbeddingFailedException::class);
    $this->assertDatabaseCount('listing_embeddings', 0);

    expect($action->handle($listing))->toBe(EmbedOutcome::Embedded);
    $this->assertDatabaseCount('listing_embeddings', 1);
});

it('keeps separate rows for different models of the same listing', function (): void {
    $listing = Listing::factory()->active()->create();
    app(EmbedListing::class)->handle($listing);

    $other = new EmbedListing(new FakeEmbeddingClient(1024, model: 'voyage-3'));
    $other->handle($listing);

    $this->assertDatabaseCount('listing_embeddings', 2);
});
