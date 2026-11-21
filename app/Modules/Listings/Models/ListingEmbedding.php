<?php

declare(strict_types=1);

namespace App\Modules\Listings\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Колонку `embedding` (vector) навмисно не мапимо: вона ~4 КБ на рядок,
 * пишемо й читаємо її лише сирим SQL у Job-і індексації та в пошуку.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $embedding_model
 * @property string $content_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['listing_id', 'embedding_model', 'content_hash'])]
class ListingEmbedding extends Model
{
    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
