<?php

declare(strict_types=1);

namespace App\Modules\Search\Console;

use App\Modules\Search\Services\SearchCacheStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Hit rate кешів пошуку за день: яка частка запитів не дійшла до LLM / провайдера ембедингів. */
final class ShowSearchCacheStats extends Command
{
    protected $signature = 'search:cache-stats {--date= : День у UTC (Y-m-d), за замовчуванням сьогодні}';

    protected $description = 'Show search cache hit rate (parsed queries and query embeddings)';

    public function handle(SearchCacheStats $stats): int
    {
        $option = $this->option('date');
        $date = null;

        if (is_string($option) && $option !== '') {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $option, 'UTC')?->toDateString();
            } catch (Throwable) {
                $date = null;
            }

            if ($date !== $option) {
                $this->error('Use --date=YYYY-MM-DD.');

                return self::INVALID;
            }
        }

        $rows = [];
        foreach ($stats->forDate($date) as $rate) {
            $rows[] = [
                $rate->cache->value,
                number_format($rate->hits),
                number_format($rate->misses),
                $rate->rate() === null ? '—' : sprintf('%.1f%%', 100 * $rate->rate()),
            ];
        }

        $this->info(sprintf('Search cache hit rate for %s (UTC)', $date ?? CarbonImmutable::now('UTC')->toDateString()));
        $this->table(['cache', 'hits', 'misses', 'hit rate'], $rows);

        return self::SUCCESS;
    }
}
