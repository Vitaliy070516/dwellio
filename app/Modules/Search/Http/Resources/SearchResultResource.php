<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Resources;

use App\Modules\Listings\Http\Resources\ListingResource;
use App\Modules\Listings\Models\Listing;
use App\Modules\Search\DTO\ListingMatch;
use App\Modules\Search\DTO\SearchResult;
use App\Modules\Search\Enums\MatchSource;
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
            // Поля квартири + пояснення збігу. `match` = null у режимі filters_only: ранжування не було.
            'data' => array_map(
                fn (Listing $listing): array => [
                    ...(new ListingResource($listing))->resolve($request),
                    'match' => self::match($this->resource->matchFor($listing)),
                ],
                $this->listings,
            ),
            'meta' => [
                'mode' => $this->mode->value,
                'parsed_by_llm' => $this->query->parsedByLlm,
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

    /** @return array{matched_by: list<string>, ranks: array<string, int>, score: float}|null */
    private static function match(?ListingMatch $match): ?array
    {
        if ($match === null) {
            return null;
        }

        return [
            'matched_by' => array_map(static fn (MatchSource $s): string => $s->value, $match->matchedBy()),
            'ranks' => $match->ranks,
            'score' => round($match->score, 6),
        ];
    }
}
