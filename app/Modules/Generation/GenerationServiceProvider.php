<?php

declare(strict_types=1);

namespace App\Modules\Generation;

use App\Modules\Generation\Models\ListingAd;
use App\Shared\Demo\DemoMode;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

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
         * на IP (зловживання одним клієнтом) і глобальна денна стеля запитів. Третя лінія —
         * денний бюджет токенів (AI\Contracts\TokenBudget): ліміт запитів не знає, скільки коштує кожен.
         *
         * Хвилинний ліміт рахує КОЖЕН запит (захист від спаму, відповідь дешева). Денні стелі — лише
         * прийняту роботу (202): інакше кілька IP вибирають глобальну стелю дешевими 409/422/404 (День 13),
         * і генерація стає недоступною всім без жодного виклику LLM. Ціна — можливий перебір на кілька
         * паралельних запитів (перевірка до, лічба після відповіді); тверда межа — бюджет токенів.
         */
        /*
         * POST /api/broadcasting/auth (День 14): токен 48 символів не підібрати, але кожен запит — пошук
         * у БД за хешем. Echo ходить сюди раз на підписку (і після перепідключення), тож 30/хв на IP
         * з запасом для живого браузера. 429 → Echo віддає `.error()`, фронт переходить на опитування GET.
         */
        RateLimiter::for('channel-auth', static fn (Request $request): Limit => Limit::perMinute(
            config()->integer('dwellio.generation.rate_limit.channel_auth_per_minute_per_ip'),
        )->by('ip:'.$request->ip()));

        RateLimiter::for('ad-generation', static function (Request $request): array {
            $limits = [
                Limit::perMinute(DemoMode::limit(
                    config()->integer('dwellio.generation.rate_limit.per_minute_per_ip'),
                    'generation_per_minute_per_ip',
                ))->by('ip:'.$request->ip()),
                Limit::perDay(DemoMode::limit(
                    config()->integer('dwellio.generation.rate_limit.per_day_global'),
                    'generation_per_day_global',
                ))->by('global')->after(self::accepted(...)),
            ];

            // Лише на демо: без автентифікації одна людина інакше вибирає глобальну денну стелю за всіх.
            $perDayPerIp = DemoMode::onlyLimit('generation_per_day_per_ip');
            if ($perDayPerIp !== null) {
                $limits[] = Limit::perDay($perDayPerIp)->by('ip-day:'.$request->ip())->after(self::accepted(...));
            }

            return $limits;
        });
    }

    /** Генерацію прийнято в чергу: лише така відповідь витрачає денну стелю. */
    private static function accepted(Response $response): bool
    {
        return $response->getStatusCode() === Response::HTTP_ACCEPTED;
    }
}
