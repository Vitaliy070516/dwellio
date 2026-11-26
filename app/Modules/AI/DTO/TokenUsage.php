<?php

declare(strict_types=1);

namespace App\Modules\AI\DTO;

use App\Modules\AI\Enums\AiPurpose;

/** Знімок денного споживання токенів для однієї цілі. */
final readonly class TokenUsage
{
    public function __construct(
        public AiPurpose $purpose,
        public string $date,
        public int $inputTokens,
        public int $outputTokens,
        public int $calls,
        public int $dailyLimit,
    ) {}

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function remaining(): int
    {
        return max(0, $this->dailyLimit - $this->total());
    }

    public function exhausted(): bool
    {
        return $this->total() >= $this->dailyLimit;
    }
}
