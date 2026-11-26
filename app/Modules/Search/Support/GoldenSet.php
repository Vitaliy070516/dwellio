<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Listings\Enums\District;
use App\Modules\Search\DTO\GoldenQuery;
use App\Modules\Search\DTO\SearchFilters;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;

/**
 * Завантаження golden set із JSON. Файл пишуть руками, тож валідуємо суворо:
 * помилка в еталоні тихо зіпсувала б метрики, а саме за ними ми крутимо параметри пошуку.
 */
final class GoldenSet
{
    /**
     * @return list<GoldenQuery>
     *
     * @throws InvalidArgumentException файл не читається, не JSON або не за схемою
     */
    public static function load(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidArgumentException("Golden set file [{$path}] is not readable.");
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Golden set [{$path}] is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @param  array<mixed>  $data
     * @return list<GoldenQuery>
     */
    public static function fromArray(array $data): array
    {
        $validator = Validator::make($data, [
            'queries' => ['required', 'array', 'min:1'],
            'queries.*.id' => ['required', 'string', 'distinct'],
            'queries.*.query' => ['required', 'string', 'max:300'],
            'queries.*.relevant' => ['required', 'array'],
            'queries.*.relevant.filters' => ['sometimes', 'array'],
            'queries.*.relevant.filters.price_min' => ['nullable', 'integer', 'min:0'],
            'queries.*.relevant.filters.price_max' => ['nullable', 'integer', 'min:0'],
            'queries.*.relevant.filters.rooms' => ['nullable', 'array'],
            'queries.*.relevant.filters.rooms.*' => ['integer', 'between:1,10'],
            'queries.*.relevant.filters.districts' => ['nullable', 'array'],
            'queries.*.relevant.filters.districts.*' => [Rule::enum(District::class)],
            'queries.*.relevant.filters.pets_allowed' => ['nullable', 'boolean'],
            'queries.*.relevant.filters.furnished' => ['nullable', 'boolean'],
            'queries.*.relevant.filters.max_metro_distance_m' => ['nullable', 'integer', 'min:0'],
            'queries.*.relevant.text' => ['sometimes', 'array'],
            'queries.*.relevant.text.*' => ['array', 'min:1'],
            'queries.*.relevant.text.*.*' => ['string', 'min:2'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid golden set: '.implode('; ', $validator->errors()->all()));
        }

        $queries = [];
        foreach (self::list($data['queries'] ?? null) as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $relevant = is_array($entry['relevant'] ?? null) ? $entry['relevant'] : [];
            $filters = self::filters(is_array($relevant['filters'] ?? null) ? $relevant['filters'] : []);
            $groups = self::textGroups($relevant['text'] ?? null);

            // Правило без жодної умови = «релевантне все»: такий запит нічого не вимірює.
            if ($filters->isEmpty() && $groups === []) {
                throw new InvalidArgumentException(sprintf('Golden query [%s] has no relevance criteria.', self::str($entry['id'] ?? '')));
            }

            $queries[] = new GoldenQuery(self::str($entry['id'] ?? ''), self::str($entry['query'] ?? ''), $filters, $groups);
        }

        return $queries;
    }

    /** @param  array<mixed>  $f */
    private static function filters(array $f): SearchFilters
    {
        $int = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
        $bool = static fn (mixed $v): ?bool => $v === null ? null : filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        $districts = [];
        foreach (self::list($f['districts'] ?? null) as $slug) {
            $district = is_string($slug) ? District::tryFrom($slug) : null;
            if ($district !== null) {
                $districts[$district->value] = $district;
            }
        }

        return new SearchFilters(
            priceMin: $int($f['price_min'] ?? null),
            priceMax: $int($f['price_max'] ?? null),
            rooms: array_values(array_filter(array_map($int, self::list($f['rooms'] ?? null)), is_int(...))),
            districts: array_values($districts),
            petsAllowed: $bool($f['pets_allowed'] ?? null),
            furnished: $bool($f['furnished'] ?? null),
            maxMetroDistanceM: $int($f['max_metro_distance_m'] ?? null),
        );
    }

    /** @return list<non-empty-list<non-empty-string>> */
    private static function textGroups(mixed $raw): array
    {
        $groups = [];
        foreach (self::list($raw) as $group) {
            $words = [];
            foreach (self::list($group) as $word) {
                $word = mb_strtolower(trim(self::str($word)));
                if ($word !== '') {
                    $words[] = $word;
                }
            }

            if ($words !== []) {
                $groups[] = $words;
            }
        }

        return $groups;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
