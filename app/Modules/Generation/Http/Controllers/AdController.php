<?php

declare(strict_types=1);

namespace App\Modules\Generation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Generation\Actions\RequestListingAd;
use App\Modules\Generation\Exceptions\AdGenerationInProgressException;
use App\Modules\Generation\Exceptions\AdQueueUnavailableException;
use App\Modules\Generation\GenerationServiceProvider;
use App\Modules\Generation\Http\Requests\GenerateAdRequest;
use App\Modules\Generation\Http\Resources\AdResource;
use App\Modules\Generation\Models\ListingAd;
use App\Modules\Listings\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class AdController extends Controller
{
    public function store(Listing $listing, GenerateAdRequest $request, RequestListingAd $action): JsonResponse
    {
        // Як і в ListingController::show: чернетки не розкриваємо (без автентифікації їхні дані витекли б у текст).
        abort_unless($listing->status->isSearchable(), 404);

        try {
            $requested = $action->handle($listing, $request->platform());
        } catch (AdGenerationInProgressException) {
            abort(409, 'Ad generation for this listing and platform is already in progress.');
        } catch (AdQueueUnavailableException) {
            abort(503, 'Ad generation is temporarily unavailable. Try again later.');
        }

        // 202 Accepted: роботу прийнято, результат буде пізніше (WebSocket + GET).
        return new AdResource($requested->ad)
            ->additional(['meta' => [
                // Показується один раз: у БД лише хеш. Передається в заголовку X-Ad-Token.
                'access_token' => $requested->accessToken,
                'token_header' => GenerationServiceProvider::TOKEN_HEADER,
                'auth_endpoint' => url('/api/broadcasting/auth'),
            ]])
            ->response()
            ->setStatusCode(202);
    }

    public function show(ListingAd $ad): AdResource
    {
        // 404 замість 401/403: без токена не розкриваємо навіть факт існування генерації.
        abort_unless(Auth::guard('ad-token')->id() === $ad->id, 404);

        return new AdResource($ad);
    }
}
