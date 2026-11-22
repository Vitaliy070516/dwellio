<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
// TestCase уже прив'язано до всього Feature; тут лише додаємо трейт (повторний extend Pest забороняє).
pest()->use(RefreshDatabase::class)->in('Feature/Database', 'Feature/Listings');
pest()->extend(TestCase::class)->in('Architecture');
