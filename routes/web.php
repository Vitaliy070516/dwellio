<?php

declare(strict_types=1);

use App\Web\Controllers\ApiDocsController;
use App\Web\Controllers\ListingPageController;
use App\Web\Controllers\SearchPageController;
use Illuminate\Support\Facades\Route;

// Демо-фронт (Blade + Vite + Echo). Шар App\Web лише компонує модулі; жоден модуль від нього не залежить.
Route::get('/', SearchPageController::class)->name('web.search');
Route::get('/listings/{listing}', ListingPageController::class)->name('web.listing');

// OpenAPI: Swagger UI + закомічена специфікація (CI перевіряє, що вона збігається з кодом).
Route::get('/docs/api', [ApiDocsController::class, 'ui'])->name('web.api-docs');
Route::get('/docs/api.json', [ApiDocsController::class, 'spec'])->name('web.api-docs.spec');

// Стара мінімальна сторінка Дня 6: генерація тепер живе в картці квартири.
Route::redirect('/ads/demo', '/');
