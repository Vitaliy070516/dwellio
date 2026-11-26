<?php

declare(strict_types=1);

namespace App\Shared\Demo;

/**
 * Публічне демо через тунель (`DEMO_MODE=true`, День 12). Одна точка, де модулі питають
 * «ми зараз на демо?» і «який ліміт діє?», щоб правила демо не розповзались по коду.
 */
final class DemoMode
{
    public static function enabled(): bool
    {
        return config()->boolean('dwellio.demo.enabled');
    }

    /**
     * Ліміт з урахуванням демо: на демо — менше з двох значень (демо ніколи не м'якше за звичайний режим).
     *
     * @param  string  $key  ключ у `dwellio.demo.rate_limit`
     */
    public static function limit(int $normal, string $key): int
    {
        if (! self::enabled()) {
            return $normal;
        }

        return min($normal, config()->integer('dwellio.demo.rate_limit.'.$key));
    }

    /** Ліміт, що існує лише на демо (поза демо — null, тобто правило не додаємо). */
    public static function onlyLimit(string $key): ?int
    {
        return self::enabled() ? config()->integer('dwellio.demo.rate_limit.'.$key) : null;
    }
}
