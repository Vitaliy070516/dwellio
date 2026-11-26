<?php

declare(strict_types=1);

namespace App\Modules\AI\DTO;

/**
 * Ціна моделі в USD за 1M токенів. Зручна властивість цієї одиниці:
 * токени × ($ за 1M) = вартість у мікродоларах ($ × 10⁻⁶) — ціле число без ділення,
 * яке можна атомарно додавати в Redis через INCRBY (float-лічильники там неточні).
 */
final readonly class ModelPrice
{
    public function __construct(
        public float $inputPerMillion,
        public float $outputPerMillion,
    ) {}

    public static function free(): self
    {
        return new self(0.0, 0.0);
    }

    public function isFree(): bool
    {
        return $this->inputPerMillion <= 0.0 && $this->outputPerMillion <= 0.0;
    }

    /** Округлення вгору: для запобіжника краще переоцінити витрату на частку центу, ніж недорахувати. */
    public function costMicroUsd(int $inputTokens, int $outputTokens): int
    {
        $micro = max(0, $inputTokens) * $this->inputPerMillion + max(0, $outputTokens) * $this->outputPerMillion;

        return (int) ceil($micro);
    }
}
