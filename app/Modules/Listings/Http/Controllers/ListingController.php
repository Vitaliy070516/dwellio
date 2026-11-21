<?php

declare(strict_types=1);

namespace App\Modules\Listings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Listings\Actions\CreateListing;
use App\Modules\Listings\Actions\DeleteListing;
use App\Modules\Listings\Actions\UpdateListing;
use App\Modules\Listings\Http\Requests\ListingRequest;
use App\Modules\Listings\Http\Resources\ListingResource;
use App\Modules\Listings\Models\Listing;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Тонкий контролер: приймає HTTP, віддає Action, повертає Resource. Бізнес-логіки тут немає.
 */
final class ListingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // orderByDesc('id') + paginate: детермінований порядок, без OFFSET-сюрпризів на дублікатах дат.
        return ListingResource::collection(
            Listing::query()->orderByDesc('id')->paginate(20),
        );
    }

    public function show(Listing $listing): ListingResource
    {
        return new ListingResource($listing);
    }

    public function store(ListingRequest $request, CreateListing $action): ListingResource
    {
        return new ListingResource($action->handle($request->toData()));
    }

    public function update(ListingRequest $request, Listing $listing, UpdateListing $action): ListingResource
    {
        return new ListingResource($action->handle($listing, $request->toData()));
    }

    public function destroy(Listing $listing, DeleteListing $action): Response
    {
        $action->handle($listing);

        return response()->noContent();
    }
}
