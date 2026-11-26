<?php

declare(strict_types=1);

namespace App\Modules\Generation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generation\Enums\Platform;
use App\Modules\Listings\Models\Listing;
use Illuminate\Contracts\View\View;

/** Мінімальна демо-сторінка: запуск генерації й живий стрім через Echo (повноцінний фронт — тиждень 2). */
final class AdDemoController extends Controller
{
    public function __invoke(): View
    {
        return view('ads.demo', [
            'listings' => Listing::query()->searchable()->orderByDesc('id')->limit(30)->get(['id', 'title', 'rooms', 'price_uah']),
            'platforms' => Platform::cases(),
        ]);
    }
}
