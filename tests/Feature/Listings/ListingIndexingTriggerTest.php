<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Listings\Jobs\IndexListingEmbedding;
use App\Modules\Listings\Models\Listing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

/**
 * Коли API ставить індексацію в чергу, а коли ні. Черга в тестах sync, тож де потрібен
 * саме факт dispatch, підміняємо її на Queue::fake().
 */
describe('on create', function (): void {
    it('indexes a new active listing', function (): void {
        $this->postJson('/api/listings', listingPayload(['status' => 'active']))->assertCreated();

        $this->assertDatabaseCount('listing_embeddings', 1);
    });

    it('does not index a draft', function (): void {
        Queue::fake();

        $this->postJson('/api/listings', listingPayload(['status' => 'draft']))->assertCreated();

        Queue::assertNotPushed(IndexListingEmbedding::class);
    });

    it('queues the job on the embeddings queue for an active listing', function (): void {
        Queue::fake();

        $id = $this->postJson('/api/listings', listingPayload(['status' => 'active']))->json('data.id');

        Queue::assertPushedOn('embeddings', IndexListingEmbedding::class, fn (IndexListingEmbedding $job): bool => $job->listingId === $id);
    });

    it('still returns 201 when the provider fails: the listing is saved, indexing is retried later', function (): void {
        Exceptions::fake();
        fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('provider down'));

        $this->postJson('/api/listings', listingPayload(['status' => 'active']))->assertCreated();

        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('listing_embeddings', 0);
        Exceptions::assertReported(EmbeddingFailedException::class);
    });

    it('still returns 201 when the queue itself is unavailable', function (): void {
        Exceptions::fake();
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('RabbitMQ is down'));
        Queue::shouldReceive('push', 'pushOn', 'later', 'laterOn')->andThrow(new RuntimeException('RabbitMQ is down'));

        $this->postJson('/api/listings', listingPayload(['status' => 'active']))->assertCreated();

        $this->assertDatabaseCount('listings', 1);
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('on update', function (): void {
    it('re-queues when the description changes on an active listing', function (): void {
        $listing = Listing::factory()->active()->create();
        Queue::fake();

        $this->putJson("/api/listings/{$listing->id}", listingPayload([
            'title' => $listing->title,
            'status' => 'active',
            'description' => 'Новий опис квартири: тихий двір і ремонт.',
        ]))->assertOk();

        Queue::assertPushed(IndexListingEmbedding::class, 1);
    });

    it('does not re-queue when only price or rooms change', function (): void {
        $listing = Listing::factory()->active()->create([
            'title' => 'Однакова назва',
            'description' => 'Однаковий опис квартири для тесту.',
        ]);
        Queue::fake();

        $this->putJson("/api/listings/{$listing->id}", listingPayload([
            'title' => 'Однакова назва',
            'description' => 'Однаковий опис квартири для тесту.',
            'status' => 'active',
            'price_uah' => 99000,
            'rooms' => 4,
        ]))->assertOk();

        Queue::assertNotPushed(IndexListingEmbedding::class);
    });

    it('queues when a draft is published', function (): void {
        $listing = Listing::factory()->draft()->create();
        Queue::fake();

        $this->putJson("/api/listings/{$listing->id}", listingPayload([
            'title' => $listing->title,
            'description' => $listing->description,
            'status' => 'active',
        ]))->assertOk();

        Queue::assertPushed(IndexListingEmbedding::class, 1);
    });

    it('does not queue when a listing is archived', function (): void {
        $listing = Listing::factory()->active()->create();
        Queue::fake();

        $this->putJson("/api/listings/{$listing->id}", listingPayload([
            'title' => $listing->title,
            'description' => $listing->description,
            'status' => ListingStatus::Archived->value,
        ]))->assertOk();

        Queue::assertNotPushed(IndexListingEmbedding::class);
    });

    it('replaces the stored vector end to end when the text changes', function (): void {
        $listing = Listing::factory()->active()->create();
        IndexListingEmbedding::dispatchSync($listing->id);
        $oldHash = DB::table('listing_embeddings')->value('content_hash');

        $this->putJson("/api/listings/{$listing->id}", listingPayload([
            'title' => $listing->title,
            'status' => 'active',
            'description' => 'Повністю інший опис: світла квартира біля парку.',
        ]))->assertOk();

        $this->assertDatabaseCount('listing_embeddings', 1);
        expect(DB::table('listing_embeddings')->value('content_hash'))->not->toBe($oldHash);
    });
});

describe('listings:reindex', function (): void {
    it('queues only active listings that have no embedding for the current model', function (): void {
        $missing = Listing::factory()->active()->count(3)->create();
        $embedded = Listing::factory()->active()->create();
        Listing::factory()->draft()->create();
        Listing::factory()->archived()->create();
        IndexListingEmbedding::dispatchSync($embedded->id);
        Queue::fake();

        $this->artisan('listings:reindex')
            ->expectsOutputToContain('Queued 3 listing(s) for model "fake-embedding"')
            ->assertSuccessful();

        Queue::assertPushed(IndexListingEmbedding::class, 3);
        foreach ($missing as $listing) {
            Queue::assertPushed(IndexListingEmbedding::class, fn (IndexListingEmbedding $job): bool => $job->listingId === $listing->id);
        }
    });

    it('queues every active listing with --all, leaving unchanged ones to the job hash check', function (): void {
        $embedded = Listing::factory()->active()->create();
        Listing::factory()->active()->create();
        Listing::factory()->draft()->create();
        IndexListingEmbedding::dispatchSync($embedded->id);
        Queue::fake();

        $this->artisan('listings:reindex', ['--all' => true])
            ->expectsOutputToContain('Queued 2 listing(s)')
            ->assertSuccessful();

        Queue::assertPushed(IndexListingEmbedding::class, 2);
    });

    it('does nothing and says so when everything is indexed', function (): void {
        Queue::fake();

        $this->artisan('listings:reindex')->expectsOutputToContain('Queued 0 listing(s)')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('fills the index when run against a real (sync) queue', function (): void {
        Listing::factory()->active()->count(3)->create();

        $this->artisan('listings:reindex')->assertSuccessful();

        $this->assertDatabaseCount('listing_embeddings', 3);
    });
});
