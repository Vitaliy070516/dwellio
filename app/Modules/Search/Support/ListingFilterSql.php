<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Search\DTO\SearchFilters;

/**
 * Єдине місце, де фільтри стають SQL. Усі значення йдуть біндінгами; рядкові
 * літерали в тексті запиту лише константи. Той самий фрагмент використовують
 * усі гілки hybrid search, тож фільтр не може «забутися» в одній із них.
 */
final class ListingFilterSql
{
    /**
     * @return array{0: string, 1: list<int|string|bool>} SQL-умова (без `WHERE`) і біндінги
     */
    public static function build(SearchFilters $filters): array
    {
        $sql = ['listings.deleted_at IS NULL', 'listings.status = ?'];
        $bindings = [ListingStatus::Active->value];

        if ($filters->priceMin !== null) {
            $sql[] = 'listings.price_uah >= ?';
            $bindings[] = $filters->priceMin;
        }

        if ($filters->priceMax !== null) {
            $sql[] = 'listings.price_uah <= ?';
            $bindings[] = $filters->priceMax;
        }

        if ($filters->rooms !== []) {
            $sql[] = 'listings.rooms IN ('.self::placeholders($filters->rooms).')';
            array_push($bindings, ...$filters->rooms);
        }

        if ($filters->districts !== []) {
            $sql[] = 'listings.district IN ('.self::placeholders($filters->districts).')';
            array_push($bindings, ...array_map(static fn (District $d): string => $d->value, $filters->districts));
        }

        if ($filters->petsAllowed !== null) {
            $sql[] = 'listings.pets_allowed = ?';
            $bindings[] = $filters->petsAllowed;
        }

        if ($filters->furnished !== null) {
            $sql[] = 'listings.furnished = ?';
            $bindings[] = $filters->furnished;
        }

        if ($filters->maxMetroDistanceM !== null) {
            // NULL (метро невідоме) не проходить фільтр: краще пропустити, ніж збрехати «5 хвилин до метро».
            $sql[] = 'listings.metro_distance_m <= ?';
            $bindings[] = $filters->maxMetroDistanceM;
        }

        return [implode(' AND ', $sql), $bindings];
    }

    /** @param  array<array-key, mixed>  $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
