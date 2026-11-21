<?php

declare(strict_types=1);

use App\Modules\Listings\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;

// Лише PUT (повна заміна): часткового PATCH поки немає, див. ListingData.
Route::apiResource('listings', ListingController::class)->except('update');
Route::put('listings/{listing}', [ListingController::class, 'update'])->name('listings.update');
