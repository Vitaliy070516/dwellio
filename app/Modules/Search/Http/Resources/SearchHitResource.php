<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Resources;

use App\Modules\Listings\Http\Resources\ListingResource;
use App\Modules\Listings\Models\Listing;
use App\Modules\Search\DTO\ListingMatch;
use App\Modules\Search\Enums\MatchSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Один елемент видачі: усі поля квартири (як у `ListingResource`) + пояснення збігу `match`.
 * Окремий клас, а не масив усередині SearchResultResource: так форму елемента видно в OpenAPI.
 *
 * @property Listing $resource
 */
final class SearchHitResource extends JsonResource
{
    public function __construct(Listing $listing, private readonly ?ListingMatch $match)
    {
        parent::__construct($listing);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ListingResource($this->resource)->toArray($request),
            // null у режимі filters_only: ранжування не було.
            'match' => $this->match === null ? null : [
                /** @var list<MatchSource> Гілки пошуку, що знайшли квартиру. */
                'matched_by' => array_map(static fn (MatchSource $s): string => $s->value, $this->match->matchedBy()),
                /** @var array<string, int> Гілка → позиція в ній (1 = найкраща). */
                'ranks' => $this->match->ranks,
                /** Підсумковий RRF-скор (більше = вище у видачі). */
                'score' => round($this->match->score, 6),
            ],
        ];
    }
}
