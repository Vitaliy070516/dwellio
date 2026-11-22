<?php

declare(strict_types=1);

namespace App\Modules\Listings\DTO;

use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;

/**
 * Незмінний вхід для Actions створення й повної заміни (PUT) квартири.
 * Actions не бачать Request: контролер збирає DTO з провалідованих даних.
 *
 * @phpstan-type ListingInput array{
 *     title: string,
 *     description: string,
 *     price_uah: int|numeric-string,
 *     rooms: int|numeric-string,
 *     area_m2: int|float|numeric-string,
 *     floor: int|numeric-string,
 *     floors_total: int|numeric-string,
 *     district: string,
 *     address: string,
 *     metro_station?: string|null,
 *     metro_distance_m?: int|numeric-string|null,
 *     lat?: int|float|numeric-string|null,
 *     lng?: int|float|numeric-string|null,
 *     pets_allowed?: bool|int|string,
 *     furnished?: bool|int|string,
 *     status?: string,
 * }
 *
 * Валідатор НЕ приводить типи: form-encoded запит дає рядки ("18000", "1"),
 * а конструктор під strict_types їх не прийме. Тому всі касти — тут, в одному місці.
 */
final readonly class ListingData
{
    public function __construct(
        public string $title,
        public string $description,
        public int $priceUah,
        public int $rooms,
        public float $areaM2,
        public int $floor,
        public int $floorsTotal,
        public District $district,
        public string $address,
        public ?string $metroStation,
        public ?int $metroDistanceM,
        public ?float $lat,
        public ?float $lng,
        public bool $petsAllowed,
        public bool $furnished,
        public ListingStatus $status = ListingStatus::Draft,
    ) {}

    /**
     * @param  ListingInput  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            title: $data['title'],
            description: $data['description'],
            priceUah: (int) $data['price_uah'],
            rooms: (int) $data['rooms'],
            areaM2: (float) $data['area_m2'],
            floor: (int) $data['floor'],
            floorsTotal: (int) $data['floors_total'],
            district: District::from($data['district']),
            address: $data['address'],
            metroStation: $data['metro_station'] ?? null,
            metroDistanceM: isset($data['metro_distance_m']) ? (int) $data['metro_distance_m'] : null,
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lng: isset($data['lng']) ? (float) $data['lng'] : null,
            petsAllowed: filter_var($data['pets_allowed'] ?? false, FILTER_VALIDATE_BOOL),
            furnished: filter_var($data['furnished'] ?? false, FILTER_VALIDATE_BOOL),
            status: isset($data['status']) ? ListingStatus::from($data['status']) : ListingStatus::Draft,
        );
    }

    /**
     * Атрибути для Eloquent (snake_case; enum-и Eloquent сам приведе через casts).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'price_uah' => $this->priceUah,
            'rooms' => $this->rooms,
            'area_m2' => $this->areaM2,
            'floor' => $this->floor,
            'floors_total' => $this->floorsTotal,
            'district' => $this->district,
            'address' => $this->address,
            'metro_station' => $this->metroStation,
            'metro_distance_m' => $this->metroDistanceM,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'pets_allowed' => $this->petsAllowed,
            'furnished' => $this->furnished,
            'status' => $this->status,
        ];
    }
}
