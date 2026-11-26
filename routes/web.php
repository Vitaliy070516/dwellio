<?php

declare(strict_types=1);

use App\Web\Controllers\ListingPageController;
use App\Web\Controllers\SearchPageController;
use Illuminate\Support\Facades\Route;

// Демо-фронт (Blade + Vite + Echo). Шар App\Web лише компонує модулі; жоден модуль від нього не залежить.
Route::get('/', SearchPageController::class)->name('web.search');
Route::get('/listings/{listing}', ListingPageController::class)->name('web.listing');

// Стара мінімальна сторінка Дня 6: генерація тепер живе в картці квартири.
Route::redirect('/ads/demo', '/');
