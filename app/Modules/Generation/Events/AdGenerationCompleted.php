<?php

declare(strict_types=1);

namespace App\Modules\Generation\Events;

/** Без тексту: фінальний результат клієнт бере через GET (джерело істини — БД, і повідомлення лишається малим). */
final class AdGenerationCompleted extends AdGenerationEvent
{
    public function broadcastAs(): string
    {
        return 'ad.completed';
    }

    public function broadcastWith(): array
    {
        return ['ad_id' => $this->adId];
    }
}
