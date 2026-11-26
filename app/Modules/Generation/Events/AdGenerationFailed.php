<?php

declare(strict_types=1);

namespace App\Modules\Generation\Events;

use App\Modules\Generation\Enums\AdFailure;

final class AdGenerationFailed extends AdGenerationEvent
{
    public function __construct(string $adId, public readonly AdFailure $failure)
    {
        parent::__construct($adId);
    }

    public function broadcastAs(): string
    {
        return 'ad.failed';
    }

    public function broadcastWith(): array
    {
        return ['ad_id' => $this->adId, 'code' => $this->failure->value];
    }
}
