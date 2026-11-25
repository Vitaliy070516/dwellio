<?php

declare(strict_types=1);

use App\Modules\Listings\Http\Controllers\ListingController;
use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Лише PUT (повна заміна): часткового PATCH поки немає, див. ListingData.
Route::apiResource('listings', ListingController::class)->except('update');
Route::put('listings/{listing}', [ListingController::class, 'update'])->name('listings.update');

// GET: пошук ідемпотентний і кешований. Rate limit (токени LLM) додається на Дні 7.
Route::get('search', SearchController::class)->name('search');
