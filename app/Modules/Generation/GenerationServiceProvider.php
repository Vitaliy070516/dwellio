<?php

declare(strict_types=1);

namespace App\Modules\Generation;

use App\Modules\Generation\Models\ListingAd;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class GenerationServiceProvider extends ServiceProvider
{
    public const string TOKEN_HEADER = 'X-Ad-Token';

    public function boot(): void
    {
        /*
         * Guard `ad-token`: доступ за секретом однієї генерації (користувачів у MVP немає).
         * «Користувач» тут — сама генерація: id = UUID запису. Цим guard-ом захищені
         * GET /api/listing-ads/{id} і авторизація приватного каналу listing-ads.{id}.
         */
        Auth::viaRequest('ad-token', static function (Request $request): ?GenericUser {
            $token = $request->header(self::TOKEN_HEADER);

            if (! is_string($token) || $token === '') {
                return null;
            }

            $adId = ListingAd::query()
                ->where('access_token_hash', ListingAd::hashToken($token))
                ->value('id');

            return is_string($adId) ? new GenericUser(['id' => $adId]) : null;
        });

        /*
         * Кожен POST запускає платний виклик LLM, а автентифікації немає. Тому два ліміти:
         * на IP (зловживання одним клієнтом) і глобальна денна стеля (FinOps-запобіжник).
         * Облік токенів у Redis — День 7.
         */
        RateLimiter::for('ad-generation', static fn (Request $request): array => [
            Limit::perMinute(config()->integer('dwellio.generation.rate_limit.per_minute_per_ip'))
                ->by('ip:'.$request->ip()),
            Limit::perDay(config()->integer('dwellio.generation.rate_limit.per_day_global'))
                ->by('global'),
        ]);
    }
}
