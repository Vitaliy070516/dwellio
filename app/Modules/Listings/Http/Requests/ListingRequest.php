<?php

declare(strict_types=1);

namespace App\Modules\Listings\Http\Requests;

use App\Modules\Listings\DTO\ListingData;
use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Спільні правила для створення (POST) і повної заміни (PUT).
 *
 * @phpstan-import-type ListingInput from ListingData
 */
final class ListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Авторизації ще немає (поза скоупом MVP); політики з'являться разом з акаунтами.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'price_uah' => ['required', 'integer', 'min:1', 'max:10000000'],
            'rooms' => ['required', 'integer', 'min:1', 'max:10'],
            'area_m2' => ['required', 'numeric', 'min:5', 'max:1000'],
            'floor' => ['required', 'integer', 'min:1', 'max:100'],
            'floors_total' => ['required', 'integer', 'min:1', 'max:100', 'gte:floor'],
            'district' => ['required', Rule::enum(District::class)],
            'address' => ['required', 'string', 'max:255'],
            'metro_station' => ['nullable', 'string', 'max:64'],
            'metro_distance_m' => ['nullable', 'integer', 'min:0', 'max:20000'],
            // Грубі межі навколо Києва: відсікають переплутані lat/lng.
            'lat' => ['nullable', 'numeric', 'between:50,51'],
            'lng' => ['nullable', 'numeric', 'between:30,31'],
            'pets_allowed' => ['sometimes', 'boolean'],
            'furnished' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(ListingStatus::class)],
        ];
    }

    public function toData(): ListingData
    {
        /** @var ListingInput $validated */
        $validated = $this->validated();

        return ListingData::fromValidated($validated);
    }
}
