<?php

declare(strict_types=1);

namespace App\Modules\Search;

use App\Shared\Demo\DemoMode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class SearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Перша лінія захисту бюджету: ліміт запитів на IP (лічильник у Redis, спільний для всіх реплік).
         * Друга — денний бюджет токенів у модулі AI: навіть ботнет з тисячі IP не пройде повз нього,
         * але й не покладе пошук — той просто деградує до SQL/FTS без LLM.
         */
        RateLimiter::for('search', static fn (Request $request): Limit => Limit::perMinute(DemoMode::limit(
            config()->integer('dwellio.search.rate_limit.per_minute_per_ip'),
            'search_per_minute_per_ip',
        ))->by('ip:'.$request->ip()));
    }
}
