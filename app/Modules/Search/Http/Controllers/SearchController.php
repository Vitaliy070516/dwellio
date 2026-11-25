<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Search\Actions\SearchListings;
use App\Modules\Search\Http\Requests\SearchRequest;
use App\Modules\Search\Http\Resources\SearchResultResource;

final class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchListings $action): SearchResultResource
    {
        return new SearchResultResource(
            $action->handle($request->queryText(), $request->explicitFilters(), $request->limit()),
        );
    }
}
