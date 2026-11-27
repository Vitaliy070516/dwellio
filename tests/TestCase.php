<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * HTML-сторінки підключають @vite, а public/build є лише там, де хтось запускав `npm run build`.
     * Локально тест зеленіє через старий білд, у CI (без білда) — 500. Вимикаємо для всіх тестів одразу,
     * щоб новий тест сторінки не залежав від того, чи не забули тут викликати withoutVite().
     * Логіку фронту перевіряє окремий job Vitest; сам `npm run build` у CI поки не запускається.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Кешований конфіг ігнорує <env> з phpunit.xml: тести пішли б у справжню БД, і RefreshDatabase
     * стер би її. Перевіряємо тут, а не в setUp(): трейти (RefreshDatabase) запускаються ще в parent::setUp().
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()) {
            throw new RuntimeException('Config is cached ('.$app->getCachedConfigPath().'): run `artisan config:clear` before tests.');
        }

        return $app;
    }
}
