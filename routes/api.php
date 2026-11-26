<?php

declare(strict_types=1);

use App\Modules\Generation\Http\Controllers\AdController;
use App\Modules\Listings\Http\Controllers\ListingController;
use App\Modules\Search\Http\Controllers\SearchController;
use App\Shared\Http\Middleware\ReadOnlyInDemo;
use Illuminate\Support\Facades\Route;

// Лише PUT (повна заміна): часткового PATCH поки немає, див. ListingData.
// Запис на публічному демо закрито (403 demo_read_only), читання працює завжди.
Route::apiResource('listings', ListingController::class)->only(['index', 'show']);
Route::middleware(ReadOnlyInDemo::ALIAS)->group(static function (): void {
    Route::post('listings', [ListingController::class, 'store'])->name('listings.store');
    Route::put('listings/{listing}', [ListingController::class, 'update'])->name('listings.update');
    Route::delete('listings/{listing}', [ListingController::class, 'destroy'])->name('listings.destroy');
});

// GET: пошук ідемпотентний і кешований. Throttle на IP + денний бюджет токенів у модулі AI.
Route::get('search', SearchController::class)
    ->middleware('throttle:search')
    ->name('search');

// Генерація оголошень: POST ставить Job у чергу (202), прогрес іде у WebSocket, результат — через GET.
Route::post('listings/{listing}/ads', [AdController::class, 'store'])
    ->middleware('throttle:ad-generation')
    ->name('listing-ads.store');
Route::get('listing-ads/{ad}', [AdController::class, 'show'])->name('listing-ads.show');
