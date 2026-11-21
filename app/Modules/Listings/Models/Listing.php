<?php

declare(strict_types=1);

namespace App\Modules\Listings\Models;

use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $title
 * @property string $description
 * @property int $price_uah
 * @property int $rooms
 * @property string $area_m2
 * @property int $floor
 * @property int $floors_total
 * @property District $district
 * @property string $address
 * @property string|null $metro_station
 * @property int|null $metro_distance_m
 * @property string|null $lat
 * @property string|null $lng
 * @property bool $pets_allowed
 * @property bool $furnished
 * @property ListingStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'title', 'description', 'price_uah', 'rooms', 'area_m2', 'floor', 'floors_total',
    'district', 'address', 'metro_station', 'metro_distance_m', 'lat', 'lng',
    'pets_allowed', 'furnished', 'status',
])]
class Listing extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'district' => District::class,
            'status' => ListingStatus::class,
            'area_m2' => 'decimal:2',
            'lat' => 'decimal:6',
            'lng' => 'decimal:6',
            'pets_allowed' => 'boolean',
            'furnished' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ListingEmbedding, $this>
     */
    public function embeddings(): HasMany
    {
        return $this->hasMany(ListingEmbedding::class);
    }

    /** Текст, з якого рахується ембединг (хеш від нього — ключ ідемпотентності на Дні 4). */
    public function embeddingSource(): string
    {
        return trim($this->title."\n".$this->description);
    }
}
