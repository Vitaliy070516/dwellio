<?php

declare(strict_types=1);

namespace App\Modules\Generation\Models;

use App\Modules\Generation\Enums\AdFailure;
use App\Modules\Generation\Enums\AdStatus;
use App\Modules\Generation\Enums\Platform;
use App\Modules\Listings\Models\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $listing_id
 * @property Platform $platform
 * @property AdStatus $status
 * @property string|null $title
 * @property string|null $body
 * @property AdFailure|null $failure_code
 * @property int $attempt
 * @property string|null $model
 * @property string $prompt_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $access_token_hash
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'listing_id', 'platform', 'status', 'title', 'body', 'failure_code', 'attempt', 'model',
    'prompt_version', 'input_tokens', 'output_tokens', 'access_token_hash', 'started_at', 'finished_at',
])]
#[Hidden(['access_token_hash'])]
class ListingAd extends Model
{
    use HasUuids;

    /** Ім'я приватного каналу, на який підписується браузер (див. routes/channels.php). */
    public const string CHANNEL_PREFIX = 'listing-ads.';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => AdStatus::class,
            'failure_code' => AdFailure::class,
            'attempt' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function channelName(): string
    {
        return self::CHANNEL_PREFIX.$this->id;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
