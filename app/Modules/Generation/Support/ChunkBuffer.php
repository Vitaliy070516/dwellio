<?php

declare(strict_types=1);

namespace App\Modules\Generation\Support;

use Closure;

/**
 * Збирає дельти LLM у пакети: ~10 повідомлень/с замість сотні дрібних по 1–3 символи.
 * Перша дельта йде одразу (швидкий «перший токен» для UX), далі — не частіше ніж раз на інтервал.
 * Пакет ділиться на шматки до maxChars, щоб не впертися в ліміт повідомлення Reverb.
 *
 * Буфер не має таймера: якщо модель замовкла, залишок піде з наступною дельтою або з flush().
 */
final class ChunkBuffer
{
    private string $buffer = '';

    private ?float $lastFlushMs = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var positive-int */
    private readonly int $maxChars;

    /**
     * @param  (Closure(): float)|null  $clock  монотонний час у мс (у тестах — керований)
     */
    public function __construct(
        private readonly int $intervalMs,
        int $maxChars,
        ?Closure $clock = null,
    ) {
        // 0 або від'ємне значення з конфігу інакше зламало б mb_str_split посеред стріму.
        $this->maxChars = max(1, $maxChars);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    /** @return list<string> пакети, готові до відправки (можливо, порожній список) */
    public function push(string $delta): array
    {
        $this->buffer .= $delta;

        $now = ($this->clock)();
        $due = $this->lastFlushMs === null || $now - $this->lastFlushMs >= $this->intervalMs;

        if (! $due && mb_strlen($this->buffer) < $this->maxChars) {
            return [];
        }

        $this->lastFlushMs = $now;

        return $this->drain();
    }

    /** @return list<string> */
    public function flush(): array
    {
        return $this->drain();
    }

    /** @return list<string> */
    private function drain(): array
    {
        if ($this->buffer === '') {
            return [];
        }

        $chunks = mb_str_split($this->buffer, $this->maxChars);
        $this->buffer = '';

        return $chunks;
    }
}
