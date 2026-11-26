<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\Generation\Enums\AdFailure;
use App\Modules\Generation\Enums\AdStatus;
use App\Modules\Generation\Jobs\GenerateListingAdJob;
use App\Modules\Generation\Models\ListingAd;
use App\Modules\Listings\Models\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('accepts a generation request, queues the job and returns a one-time token', function (): void {
    $listing = Listing::factory()->active()->create();

    $response = $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx']);

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.platform', 'olx')
        ->assertJsonPath('meta.token_header', 'X-Ad-Token')
        ->assertJsonMissingPath('data.access_token_hash');

    $adId = $response->json('data.id');
    $token = $response->json('meta.access_token');
    expect($response->json('data.channel'))->toBe("listing-ads.{$adId}");

    // У БД — лише хеш токена.
    $ad = ListingAd::query()->findOrFail($adId);
    expect($ad->access_token_hash)->toBe(ListingAd::hashToken($token))->not->toBe($token);

    Queue::assertPushedOn('generation', GenerateListingAdJob::class, fn (GenerateListingAdJob $job): bool => $job->adId === $adId);
});

it('rejects an unknown platform', function (): void {
    $listing = Listing::factory()->active()->create();

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'avito'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform');

    Queue::assertNothingPushed();
});

it('does not generate ads for drafts or deleted listings', function (Closure $makeListing): void {
    $listing = $makeListing();

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertNotFound();

    Queue::assertNothingPushed();
})->with([
    'draft' => [fn () => Listing::factory()->create(['status' => 'draft'])],
    'deleted' => [function () {
        $listing = Listing::factory()->active()->create();
        $listing->delete();

        return $listing;
    }],
]);

it('returns 409 for a double click instead of paying for a second LLM call', function (): void {
    $listing = Listing::factory()->active()->create();

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertAccepted();
    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertConflict();

    Queue::assertPushed(GenerateListingAdJob::class, 1);
    $this->assertDatabaseCount('listing_ads', 1);
});

it('allows parallel generations for different platforms of the same listing', function (): void {
    $listing = Listing::factory()->active()->create();

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertAccepted();
    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'rieltor'])->assertAccepted();

    Queue::assertPushed(GenerateListingAdJob::class, 2);
});

it('allows a new generation once the previous one has finished', function (AdStatus $status): void {
    $listing = Listing::factory()->active()->create();
    createAd($listing, ['status' => $status]);

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertAccepted();
})->with([AdStatus::Completed, AdStatus::Failed]);

it('expires a stuck generation instead of blocking the listing forever', function (): void {
    $listing = Listing::factory()->active()->create();
    $stuck = createAd($listing, ['status' => AdStatus::Processing]);
    $stuck->forceFill(['created_at' => now()->subHour()])->save();

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertAccepted();

    expect($stuck->refresh()->status)->toBe(AdStatus::Failed)->and($stuck->failure_code)->toBe(AdFailure::Stale);
});

it('answers 503 and closes the record when RabbitMQ is unavailable', function (): void {
    $listing = Listing::factory()->active()->create();
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('AMQP connection refused'));

    $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertServiceUnavailable();

    $ad = ListingAd::query()->sole();
    expect($ad->status)->toBe(AdStatus::Failed)->and($ad->failure_code)->toBe(AdFailure::QueueUnavailable);
});

it('rate-limits generation requests per IP', function (): void {
    config(['dwellio.generation.rate_limit.per_minute_per_ip' => 2]);
    $listings = Listing::factory()->active()->count(3)->create();

    $this->postJson("/api/listings/{$listings[0]->id}/ads", ['platform' => 'olx'])->assertAccepted();
    $this->postJson("/api/listings/{$listings[1]->id}/ads", ['platform' => 'olx'])->assertAccepted();
    $this->postJson("/api/listings/{$listings[2]->id}/ads", ['platform' => 'olx'])->assertTooManyRequests();
});

it('shows the result only to the holder of the token', function (): void {
    $ad = createAd(overrides: ['status' => AdStatus::Completed, 'title' => 'Заголовок', 'body' => 'Текст оголошення'], token: 'secret-a');
    createAd(token: 'secret-b');

    $this->getJson("/api/listing-ads/{$ad->id}", ['X-Ad-Token' => 'secret-a'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.title', 'Заголовок')
        ->assertJsonPath('data.body', 'Текст оголошення');

    // Без токена, з чужим токеном, із вигаданим — однаковий 404: не розкриваємо факт існування.
    // forgetGuards(): у тесті всі запити йдуть в один екземпляр застосунку, і RequestGuard
    // пам'ятав би користувача попереднього запиту (у php-fpm кожен запит — новий процес-стан).
    foreach ([[], ['X-Ad-Token' => 'secret-b'], ['X-Ad-Token' => 'guess']] as $headers) {
        app('auth')->forgetGuards();
        $this->getJson("/api/listing-ads/{$ad->id}", $headers)->assertNotFound();
    }
});

it('answers 404, not 500, for a malformed id', function (): void {
    $this->getJson('/api/listing-ads/not-a-uuid', ['X-Ad-Token' => 'test-token'])->assertNotFound();
});

it('answers 503 with Retry-After and queues nothing once the daily generation budget is spent', function (): void {
    $listing = Listing::factory()->active()->create();
    exhaustTokenBudget(AiPurpose::Generation);

    $response = $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])
        ->assertServiceUnavailable();

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(86400);
    $this->assertDatabaseCount('listing_ads', 0);
    Queue::assertNothingPushed();
});

it('answers 503 until next month once the monthly spend cap is reached by a paid model', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 23:00:00', 'UTC'));
    config([
        'dwellio.ai.generation.driver' => 'laravel-ai',
        'dwellio.ai.generation.provider' => 'anthropic',
        'dwellio.ai.generation.model' => 'claude-sonnet-5-5',
        'dwellio.ai.budget.daily_usd' => 100,
        'dwellio.ai.budget.monthly_usd' => 0.01,
    ]);
    app()->forgetInstance(TokenBudget::class);
    // 1 000 output × $10 / 1M = $0.01 — рівно місячна стеля.
    app(TokenBudget::class)->record(AiPurpose::Generation, 0, 1000);
    $listing = Listing::factory()->active()->create();

    $response = $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Monthly AI budget is exhausted. Try again next month.');

    expect($response->headers->get('Retry-After'))->toBe('3600');
    Queue::assertNothingPushed();

    CarbonImmutable::setTestNow();
});
