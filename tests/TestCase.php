<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
