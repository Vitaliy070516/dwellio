<?php

declare(strict_types=1);

use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Listings\Models\Listing;

describe('GET /api/listings', function (): void {
    it('returns the newest listings first, 20 per page', function (): void {
        $listings = Listing::factory()->count(25)->active()->create();

        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('data.0.id', $listings->max('id'));

        $this->getJson('/api/listings?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });

    it('does not return soft-deleted listings', function (): void {
        $kept = Listing::factory()->active()->create();
        Listing::factory()->active()->create()->delete();

        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id);
    });

    it('hides drafts and archived listings', function (): void {
        $active = Listing::factory()->active()->create();
        Listing::factory()->draft()->create();
        Listing::factory()->archived()->create();

        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    });

    it('returns an empty page when there are no listings', function (): void {
        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    });
});

describe('GET /api/listings/{id}', function (): void {
    it('returns the listing with a stable shape', function (): void {
        $listing = Listing::factory()->create([
            'district' => District::Pecherskyi,
            'area_m2' => 42.5,
            'status' => ListingStatus::Active,
        ]);

        $this->getJson("/api/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $listing->id)
            ->assertJsonPath('data.district', 'pecherskyi')
            ->assertJsonPath('data.district_label', 'Печерський')
            ->assertJsonPath('data.area_m2', 42.5)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.embedding');
    });

    it('returns 404 for a missing listing', function (): void {
        $this->getJson('/api/listings/999999')->assertNotFound();
    });

    it('returns 404 for a draft or archived listing', function (string $state): void {
        $listing = Listing::factory()->{$state}()->create();

        $this->getJson("/api/listings/{$listing->id}")->assertNotFound();
    })->with(['draft', 'archived']);

    it('returns 404 for a soft-deleted listing', function (): void {
        $listing = Listing::factory()->create();
        $listing->delete();

        $this->getJson("/api/listings/{$listing->id}")->assertNotFound();
    });
});

describe('POST /api/listings', function (): void {
    it('creates a listing', function (): void {
        $this->postJson('/api/listings', listingPayload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Затишна 2-кімнатна на Подолі')
            ->assertJsonPath('data.district', 'podilskyi')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('listings', [
            'title' => 'Затишна 2-кімнатна на Подолі',
            'price_uah' => 18000,
            'district' => 'podilskyi',
            'status' => 'active',
            'pets_allowed' => true,
        ]);
    });

    it('applies defaults when optional fields are omitted', function (): void {
        $payload = listingPayload();
        unset($payload['status'], $payload['pets_allowed'], $payload['furnished'], $payload['metro_station'], $payload['metro_distance_m'], $payload['lat'], $payload['lng']);

        $this->postJson('/api/listings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.pets_allowed', false)
            ->assertJsonPath('data.furnished', false)
            ->assertJsonPath('data.metro_station', null)
            ->assertJsonPath('data.lat', null);
    });

    it('accepts form-encoded input where every value arrives as a string', function (): void {
        $this->post('/api/listings', [
            ...listingPayload(),
            'price_uah' => '18000',
            'rooms' => '2',
            'area_m2' => '54.5',
            'floor' => '3',
            'floors_total' => '9',
            'metro_distance_m' => '400',
            'pets_allowed' => '1',
            'furnished' => '0',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.price_uah', 18000)
            ->assertJsonPath('data.pets_allowed', true)
            ->assertJsonPath('data.furnished', false);
    });

    it('returns JSON validation errors even without an Accept header', function (): void {
        $this->post('/api/listings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'price_uah', 'district']);
    });

    it('rejects invalid input and stores nothing', function (string $field, mixed $value): void {
        $this->postJson('/api/listings', listingPayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('listings', 0);
    })->with([
        'title missing' => ['title', null],
        'description too short' => ['description', 'abc'],
        'price is zero' => ['price_uah', 0],
        'price is not an integer' => ['price_uah', 'дорого'],
        'too many rooms' => ['rooms', 11],
        'area is not numeric' => ['area_m2', 'abc'],
        'unknown district' => ['district', 'atlantis'],
        'latitude outside Kyiv' => ['lat', 10],
        'longitude outside Kyiv' => ['lng', 99],
        'unknown status' => ['status', 'deleted'],
        'pets flag is not boolean' => ['pets_allowed', 'maybe'],
    ]);

    it('rejects floors_total lower than floor', function (): void {
        $this->postJson('/api/listings', listingPayload(['floor' => 5, 'floors_total' => 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['floors_total']);
    });
});

describe('PUT /api/listings/{id}', function (): void {
    it('replaces the listing', function (): void {
        $listing = Listing::factory()->create(['price_uah' => 10000, 'status' => ListingStatus::Draft]);

        $this->putJson("/api/listings/{$listing->id}", listingPayload(['price_uah' => 21000, 'status' => 'archived']))
            ->assertOk()
            ->assertJsonPath('data.id', $listing->id)
            ->assertJsonPath('data.price_uah', 21000)
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'price_uah' => 21000, 'status' => 'archived']);
        $this->assertDatabaseCount('listings', 1);
    });

    it('leaves the listing untouched when validation fails', function (): void {
        $listing = Listing::factory()->create(['price_uah' => 10000]);

        $this->putJson("/api/listings/{$listing->id}", listingPayload(['price_uah' => -5]))
            ->assertUnprocessable();

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'price_uah' => 10000]);
    });

    it('returns 404 for a missing listing', function (): void {
        $this->putJson('/api/listings/999999', listingPayload())->assertNotFound();
    });

    it('does not expose PATCH (partial update is not supported yet)', function (): void {
        $listing = Listing::factory()->create();

        $this->patchJson("/api/listings/{$listing->id}", ['price_uah' => 1])->assertStatus(405);
    });
});

describe('DELETE /api/listings/{id}', function (): void {
    it('soft deletes the listing', function (): void {
        $listing = Listing::factory()->create();

        $this->deleteJson("/api/listings/{$listing->id}")->assertNoContent();

        $this->assertSoftDeleted('listings', ['id' => $listing->id]);
    });

    it('returns 404 when deleting twice', function (): void {
        $listing = Listing::factory()->create();

        $this->deleteJson("/api/listings/{$listing->id}")->assertNoContent();
        $this->deleteJson("/api/listings/{$listing->id}")->assertNotFound();
    });
});
