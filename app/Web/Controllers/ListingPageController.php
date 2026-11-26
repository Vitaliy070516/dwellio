<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generation\Enums\Platform;
use App\Modules\Listings\Models\Listing;
use Illuminate\Contracts\View\View;

/**
 * Картка квартири + кнопки генерації оголошення. Дані квартири рендерить Blade (екранування `{{ }}`),
 * генерацію веде JS: `POST /api/listings/{id}/ads` → Reverb → `GET /api/listing-ads/{id}`.
 */
final class ListingPageController extends Controller
{
    public function __invoke(Listing $listing): View
    {
        // Як і в API: чернетки й архів назовні не показуємо.
        abort_unless($listing->status->isSearchable(), 404);

        return view('web.listing', [
            'listing' => $listing,
            'platforms' => Platform::cases(),
        ]);
    }
}
