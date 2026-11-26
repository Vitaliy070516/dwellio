<?php

declare(strict_types=1);

use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\Listings\Actions\EmbedListing;
use App\Modules\Listings\Jobs\IndexListingEmbedding;
use App\Modules\Listings\Models\Listing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

it('indexes the listing when run', function (): void {
    $listing = Listing::factory()->active()->create();

    (new IndexListingEmbedding($listing->id))->handle(app(EmbedListing::class));

    $this->assertDatabaseHas('listing_embeddings', ['listing_id' => $listing->id]);
});

it('works end to end through the dispatcher', function (): void {
    $listing = Listing::factory()->active()->create();

    IndexListingEmbedding::dispatchSync($listing->id);

    $this->assertDatabaseCount('listing_embeddings', 1);
});

it('is idempotent: a duplicate message does not duplicate rows or provider calls', function (): void {
    $listing = Listing::factory()->active()->create();

    IndexListingEmbedding::dispatchSync($listing->id);
    IndexListingEmbedding::dispatchSync($listing->id);

    $this->assertDatabaseCount('listing_embeddings', 1);
    expect(fakeEmbeddingClient()->embeddedTexts())->toHaveCount(1);
});

it('quietly does nothing when the listing no longer exists', function (): void {
    IndexListingEmbedding::dispatchSync(999999);

    $this->assertDatabaseCount('listing_embeddings', 0);
    expect(fakeEmbeddingClient()->embeddedTexts())->toBe([]);
});

it('does nothing for a listing deleted while the job was queued', function (): void {
    $listing = Listing::factory()->active()->create();
    $listing->delete();

    IndexListingEmbedding::dispatchSync($listing->id);

    $this->assertDatabaseCount('listing_embeddings', 0);
    expect(fakeEmbeddingClient()->embeddedTexts())->toBe([]);
});

it('rethrows a transient provider failure so the worker retries with backoff', function (): void {
    $listing = Listing::factory()->active()->create();
    fakeEmbeddingClient()->failNextWith(new EmbeddingFailedException('timeout'));

    $job = (new IndexListingEmbedding($listing->id))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(EmbedListing::class)))->toThrow(EmbeddingFailedException::class);
    $job->assertNotFailed();
    $this->assertDatabaseCount('listing_embeddings', 0);
});

it('fails permanently, without retries, on an invalid embedding', function (): void {
    $listing = Listing::factory()->active()->create();
    fakeEmbeddingClient()->failNextWith(new InvalidEmbeddingException('wrong dimensions'));

    $job = (new IndexListingEmbedding($listing->id))->withFakeQueueInteractions();
    $job->handle(app(EmbedListing::class));

    $job->assertFailed();
    $this->assertDatabaseCount('listing_embeddings', 0);
});

it('fails at once, without retries, when the embeddings budget is spent (reindex picks it up tomorrow)', function (): void {
    $listing = Listing::factory()->active()->create();
    exhaustTokenBudget(AiPurpose::Embeddings);

    $job = (new IndexListingEmbedding($listing->id))->withFakeQueueInteractions();
    $job->handle(app(EmbedListing::class));

    $job->assertFailed();
    expect(fakeEmbeddingClient()->embeddedTexts())->toBe([]);
    $this->assertDatabaseCount('listing_embeddings', 0);
});

it('goes to the embeddings queue', function (): void {
    Queue::fake();

    IndexListingEmbedding::dispatch(42);

    Queue::assertPushedOn('embeddings', IndexListingEmbedding::class);
});

it('has the retry policy we documented', function (): void {
    $job = new IndexListingEmbedding(7);

    expect($job->backoff())->toBe([5, 30, 120])
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->retryUntil()->getTimestamp())
        ->toBeGreaterThan(now()->addMinutes(14)->getTimestamp())
        ->toBeLessThanOrEqual(now()->addMinutes(15)->getTimestamp());
});

it('serialises jobs per listing so an old text cannot overwrite a new one', function (): void {
    $middleware = (new IndexListingEmbedding(7))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('listing-embedding:7')
        ->and($middleware[0]->releaseAfter)->toBe(10)
        ->and($middleware[0]->expiresAfter)->toBe(300);

    // Різні квартири не блокують одна одну.
    expect((new IndexListingEmbedding(8))->middleware()[0]->key)->not->toBe($middleware[0]->key);
});

it('logs failures with ids only, never the listing text', function (): void {
    Log::spy();
    $listing = Listing::factory()->active()->create();

    (new IndexListingEmbedding($listing->id))->failed(new EmbeddingFailedException('provider down'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'listing embedding failed'
            && array_keys($context) === ['listing_id', 'exception', 'message']
            && $context['listing_id'] === $listing->id
            && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE) ?: '', $listing->description),
    );
});
