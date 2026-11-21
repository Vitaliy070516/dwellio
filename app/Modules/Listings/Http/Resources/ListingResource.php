<?php

declare(strict_types=1);

namespace App\Modules\Listings\Http\Resources;

use App\Modules\Listings\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
final class ListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price_uah' => $this->price_uah,
            'rooms' => $this->rooms,
            'area_m2' => (float) $this->area_m2,
            'floor' => $this->floor,
            'floors_total' => $this->floors_total,
            'district' => $this->district->value,
            'district_label' => $this->district->label(),
            'address' => $this->address,
            'metro_station' => $this->metro_station,
            'metro_distance_m' => $this->metro_distance_m,
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            'pets_allowed' => $this->pets_allowed,
            'furnished' => $this->furnished,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
