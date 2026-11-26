<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\Demo\DemoMode;
use App\Shared\Http\Problems\ErrorCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Передпольотний чеклист публічного демо (`make demo-up` запускає його після config:cache).
 * Кожен пункт — реальний спосіб «засвітити» щось назовні або покласти демо першим же гостем.
 */
final class CheckDemoReadiness extends Command
{
    protected $signature = 'demo:check {--web=http://localhost : Адреса веб-процесу зсередини контейнера (для наскрізної перевірки)}';

    protected $description = 'Verify the app is safe to expose through the public demo tunnel';

    /** Заглушки з .env.example: secret підписує авторизацію приватних каналів Reverb. */
    private const array PLACEHOLDER_SECRETS = ['dwellio-local-secret', 'secret', ''];

    public function handle(): int
    {
        $checks = [
            'DEMO_MODE=true' => DemoMode::enabled(),
            'APP_DEBUG=false' => ! config()->boolean('app.debug'),
            'APP_ENV=production' => app()->isProduction(),
            'config is cached' => app()->configurationIsCached(),
            'APP_KEY is set' => config('app.key') !== null && config('app.key') !== '',
            'Vite assets built (public/build/manifest.json)' => is_file(public_path('build/manifest.json')),
            'Vite dev server is off (no public/hot)' => ! is_file(public_path('hot')),
            'Reverb secret is not a placeholder' => ! in_array(config('reverb.apps.apps.0.secret'), self::PLACEHOLDER_SECRETS, true),
            'trusted proxy is pinned (not *)' => ! in_array(config('trustedproxy.proxies'), ['*', '**'], true),
            // CLI і веб — різні процеси з різним оточенням (artisan serve фільтрує змінні): перевіряємо
            // той, що реально обслуговує трафік, а не лише конфіг цього процесу.
            'web process answers in demo mode (POST /api/listings → 403 demo_read_only)' => $this->webIsInDemoMode(),
        ];

        $failed = 0;
        foreach ($checks as $name => $passed) {
            $this->line(sprintf('  %s %s', $passed ? '<info>✔</info>' : '<error>✘</error>', $name));
            $failed += $passed ? 0 : 1;
        }

        if ($failed > 0) {
            $this->error(sprintf('%d check(s) failed: do not expose the tunnel.', $failed));

            return self::FAILURE;
        }

        $this->info('Demo is ready to be exposed.');

        return self::SUCCESS;
    }

    private function webIsInDemoMode(): bool
    {
        $base = $this->option('web');

        try {
            $response = Http::acceptJson()->timeout(10)->post(rtrim(is_string($base) ? $base : '', '/').'/api/listings', []);
        } catch (Throwable) {
            return false;
        }

        return $response->status() === 403 && $response->json('code') === ErrorCode::DemoReadOnly->value;
    }
}
