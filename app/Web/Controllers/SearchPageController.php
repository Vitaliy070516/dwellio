<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Listings\Enums\District;
use Illuminate\Contracts\View\View;

/**
 * Сторінка пошуку — «оболонка»: форма і шаблон картки. Результати JS бере з публічного `GET /api/search`,
 * тобто демо-фронт — такий самий клієнт API, як і будь-який інший (контракт один, Swagger на Дні 11).
 */
final class SearchPageController extends Controller
{
    public function __invoke(): View
    {
        return view('web.search', [
            // slug → назва: фільтри з `meta.filters` приходять slug-ами, а показати треба по-людськи.
            'districts' => array_combine(
                array_map(static fn (District $d): string => $d->value, District::cases()),
                array_map(static fn (District $d): string => $d->label(), District::cases()),
            ),
        ]);
    }
}
