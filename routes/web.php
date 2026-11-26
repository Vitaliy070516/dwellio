<?php

declare(strict_types=1);

use App\Modules\Generation\Http\Controllers\AdDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ads/demo', AdDemoController::class)->name('ads.demo');
