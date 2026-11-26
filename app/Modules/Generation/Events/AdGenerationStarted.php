<?php

declare(strict_types=1);

namespace App\Modules\Generation\Events;

/** Нова спроба почалась: клієнт очищає текст попередньої спроби. */
final class AdGenerationStarted extends AdGenerationEvent
{
    public function __construct(string $adId, public readonly int $attempt)
    {
        parent::__construct($adId);
    }

    public function broadcastAs(): string
    {
        return 'ad.started';
    }

    public function broadcastWith(): array
    {
        return ['ad_id' => $this->adId, 'attempt' => $this->attempt];
    }
}
