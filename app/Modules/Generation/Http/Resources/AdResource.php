<?php

declare(strict_types=1);

namespace App\Modules\Generation\Http\Resources;

use App\Modules\Generation\Models\ListingAd;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingAd
 */
final class AdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'platform' => $this->platform->value,
            'status' => $this->status->value,
            'attempt' => $this->attempt,
            'title' => $this->title,
            'body' => $this->body,
            'failure_code' => $this->failure_code?->value,
            'model' => $this->model,
            'prompt_version' => $this->prompt_version,
            'channel' => $this->channelName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
