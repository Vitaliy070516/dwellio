<?php

declare(strict_types=1);

namespace App\Modules\Generation\Events;

use App\Modules\Generation\Models\ListingAd;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Базова подія генерації. ShouldBroadcastNow: воркер сам робить HTTP-публікацію в Reverb.
 * Через чергу кожен чанк чекав би ще один Job, а порядок чанків міг би зламатися.
 *
 * Події — лише швидкий канал доставки без гарантій: Reverb не повторює пропущене,
 * тому клієнт після перепідключення бере знімок через GET /api/listing-ads/{id}.
 */
abstract class AdGenerationEvent implements ShouldBroadcastNow
{
    public function __construct(public readonly string $adId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(ListingAd::CHANNEL_PREFIX.$this->adId);
    }

    abstract public function broadcastAs(): string;

    /** @return array<string, mixed> */
    abstract public function broadcastWith(): array;
}
