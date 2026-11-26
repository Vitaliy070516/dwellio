<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Resources;

use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Models\Listing;
use App\Modules\Search\DTO\SearchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SearchResult
 *
 * @property SearchResult $resource
 */
final class SearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Scramble не виводить тип елементів з array_map: @var робить форму елемента видимою в OpenAPI.
            /** @var list<SearchHitResource> */
            'data' => array_map(
                fn (Listing $listing): SearchHitResource => new SearchHitResource($listing, $this->resource->matchFor($listing)),
                $this->listings,
            ),
            'meta' => [
                'mode' => $this->mode->value,
                'parsed_by_llm' => $this->query->parsedByLlm,
                // Фактично застосовані фільтри: явні з query + розібрані LLM із тексту.
                /** @var array{price_min: int|null, price_max: int|null, rooms: list<int>, districts: list<District>, pets_allowed: bool|null, furnished: bool|null, max_metro_distance_m: int|null} */
                'filters' => $this->query->filters->toArray(),
                'semantic_query' => $this->query->semanticText,
                // Чому AI-крок не відпрацював (null = відпрацював або не був потрібен). Окремо від `mode`:
                // `text_only` через бюджет і через падіння провайдера клієнт показує по-різному.
                'degraded' => [
                    'query_parsing' => $this->query->failure?->value,
                    'embeddings' => $this->embeddingFailure?->value,
                ],
                'count' => count($this->listings),
            ],
        ];
    }
}
