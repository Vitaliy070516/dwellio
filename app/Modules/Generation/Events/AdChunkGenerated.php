<?php

declare(strict_types=1);

namespace App\Modules\Generation\Events;

/**
 * Пакет нових фрагментів тексту (лише приріст, не весь текст). `seq` росте з 1 у межах спроби:
 * якщо клієнт бачить пропуск, він перестає дописувати текст і чекає фінального GET.
 */
final class AdChunkGenerated extends AdGenerationEvent
{
    public function __construct(
        string $adId,
        public readonly int $attempt,
        public readonly int $seq,
        public readonly string $text,
    ) {
        parent::__construct($adId);
    }

    public function broadcastAs(): string
    {
        return 'ad.chunk';
    }

    public function broadcastWith(): array
    {
        return ['ad_id' => $this->adId, 'attempt' => $this->attempt, 'seq' => $this->seq, 'text' => $this->text];
    }
}
