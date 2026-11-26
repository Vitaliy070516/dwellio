<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Search\DTO\CacheHitRate;
use App\Modules\Search\Enums\SearchCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Денні лічильники влучань/промахів кешів пошуку (ключі search:cache:{date}:{cache}:{hits|misses}).
 *
 * Hit rate — головна FinOps-метрика пошуку: витрати на AI ≈ запити × (1 − hit rate) × ціна виклику.
 * Ті самі атомарні INCRBY, що й у бюджеті токенів; на тижні 3 ці цифри підуть у Prometheus.
 */
final class SearchCacheStats
{
    /** Довше доби, щоб `search:cache-stats --date=вчора` ще бачив учорашні цифри. */
    private const int KEY_TTL_SECONDS = 3 * 86400;

    public function record(SearchCache $cache, bool $hit): void
    {
        // Прогін `search:eval` вимикає облік: сотні штучних влучань спотворили б реальний hit rate.
        if (! config()->boolean('dwellio.search.cache_stats', true)) {
            return;
        }

        $key = self::key($cache, $hit ? 'hits' : 'misses', CarbonImmutable::now('UTC')->toDateString());

        // add = SET NX EX: TTL ставиться один раз; INCRBY його не чіпає.
        Cache::add($key, 0, self::KEY_TTL_SECONDS);
        Cache::increment($key);
    }

    /** @return list<CacheHitRate> */
    public function forDate(?string $date = null): array
    {
        $date ??= CarbonImmutable::now('UTC')->toDateString();

        $keys = [];
        foreach (SearchCache::cases() as $cache) {
            $keys[] = self::key($cache, 'hits', $date);
            $keys[] = self::key($cache, 'misses', $date);
        }

        // Один MGET на всі лічильники.
        $values = Cache::many($keys);

        return array_map(
            static fn (SearchCache $cache): CacheHitRate => new CacheHitRate(
                $cache,
                self::int($values[self::key($cache, 'hits', $date)] ?? null),
                self::int($values[self::key($cache, 'misses', $date)] ?? null),
            ),
            SearchCache::cases(),
        );
    }

    private static function key(SearchCache $cache, string $outcome, string $date): string
    {
        return sprintf('search:cache:%s:%s:%s', $date, $cache->value, $outcome);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
