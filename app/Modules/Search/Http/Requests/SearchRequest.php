<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Requests;

use App\Modules\Listings\Enums\District;
use App\Modules\Search\DTO\SearchFilters;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 300 символів: запит іде в LLM і в ембединг, довгий ввід = витрати токенів (FinOps).
            'q' => ['nullable', 'string', 'max:300'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'rooms' => ['nullable', 'array', 'max:6'],
            'rooms.*' => ['integer', 'between:1,10'],
            'districts' => ['nullable', 'array', 'max:10'],
            'districts.*' => [Rule::enum(District::class)],
            'pets_allowed' => ['nullable', 'boolean'],
            'furnished' => ['nullable', 'boolean'],
            'max_metro_distance_m' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (trim($this->queryText()) === '' && $this->explicitFilters()->isEmpty()) {
                    $validator->errors()->add('q', 'Provide a search query or at least one filter.');
                }
            },
        ];
    }

    public function queryText(): string
    {
        return $this->string('q')->trim()->toString();
    }

    public function explicitFilters(): SearchFilters
    {
        $districts = [];
        foreach ($this->array('districts') as $slug) {
            $district = is_string($slug) ? District::tryFrom($slug) : null;
            if ($district !== null) {
                $districts[$district->value] = $district;
            }
        }

        return new SearchFilters(
            priceMin: $this->filled('price_min') ? $this->integer('price_min') : null,
            priceMax: $this->filled('price_max') ? $this->integer('price_max') : null,
            rooms: array_values(array_unique(array_map(
                static fn (mixed $room): int => is_numeric($room) ? (int) $room : 0,
                $this->array('rooms'),
            ))),
            districts: array_values($districts),
            petsAllowed: $this->has('pets_allowed') && $this->input('pets_allowed') !== null ? $this->boolean('pets_allowed') : null,
            furnished: $this->has('furnished') && $this->input('furnished') !== null ? $this->boolean('furnished') : null,
            maxMetroDistanceM: $this->filled('max_metro_distance_m') ? $this->integer('max_metro_distance_m') : null,
        );
    }

    public function limit(): ?int
    {
        return $this->filled('limit') ? $this->integer('limit') : null;
    }
}
