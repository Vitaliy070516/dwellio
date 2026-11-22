<?php

declare(strict_types=1);

use App\Modules\Listings\DTO\ListingData;
use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;

function minimalListingInput(): array
{
    return [
        'title' => 'Квартира',
        'description' => 'Опис квартири для тесту',
        'price_uah' => 15000,
        'rooms' => 1,
        'area_m2' => '33.5',
        'floor' => 2,
        'floors_total' => 9,
        'district' => 'obolonskyi',
        'address' => 'просп. Перемоги, 1',
    ];
}

it('maps validated input and applies defaults for optional fields', function (): void {
    $data = ListingData::fromValidated(minimalListingInput());

    expect($data->district)->toBe(District::Obolonskyi)
        ->and($data->areaM2)->toBe(33.5)
        ->and($data->status)->toBe(ListingStatus::Draft)
        ->and($data->petsAllowed)->toBeFalse()
        ->and($data->furnished)->toBeFalse()
        ->and($data->metroStation)->toBeNull()
        ->and($data->lat)->toBeNull()
        ->and($data->lng)->toBeNull();
});

it('casts string coordinates to floats', function (): void {
    $data = ListingData::fromValidated([...minimalListingInput(), 'lat' => '50.45', 'lng' => 30.52]);

    expect($data->lat)->toBe(50.45)->and($data->lng)->toBe(30.52);
});

it('rejects an unknown district or status instead of silently accepting it', function (): void {
    expect(fn () => ListingData::fromValidated([...minimalListingInput(), 'district' => 'atlantis']))
        ->toThrow(ValueError::class);

    expect(fn () => ListingData::fromValidated([...minimalListingInput(), 'status' => 'deleted']))
        ->toThrow(ValueError::class);
});

it('exports snake_case attributes with enums intact', function (): void {
    $attributes = ListingData::fromValidated(minimalListingInput())->toAttributes();

    expect($attributes)->toHaveKeys(['price_uah', 'floors_total', 'pets_allowed', 'status'])
        ->and($attributes['district'])->toBe(District::Obolonskyi)
        ->and($attributes['status'])->toBe(ListingStatus::Draft);
});

it('has a human label for every district', function (): void {
    foreach (District::cases() as $district) {
        expect($district->label())->not->toBeEmpty();
    }

    expect(District::cases())->toHaveCount(10);
});

it('treats only active listings as searchable', function (): void {
    expect(ListingStatus::Active->isSearchable())->toBeTrue()
        ->and(ListingStatus::Draft->isSearchable())->toBeFalse()
        ->and(ListingStatus::Archived->isSearchable())->toBeFalse();
});
