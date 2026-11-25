<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Resources;

use App\Modules\Listings\Http\Resources\ListingResource;
use App\Modules\Search\DTO\SearchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SearchResult
 */
final class SearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => ListingResource::collection($this->listings),
            'meta' => [
                'mode' => $this->mode->value,
                'parsed_by_llm' => $this->query->parsedByLlm,
                'filters' => $this->query->filters->toArray(),
                'semantic_query' => $this->query->semanticText,
                'count' => count($this->listings),
            ],
        ];
    }
}
