<?php

declare(strict_types=1);

namespace App\Modules\AI\DTO;

use App\Modules\AI\Enums\FinishReason;

/** Підсумок стріму: зберігається разом із результатом для обліку витрат (FinOps). */
final readonly class TextStreamResult
{
    public function __construct(
        public string $model,
        public FinishReason $finishReason,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function completed(): bool
    {
        return $this->finishReason === FinishReason::Stop;
    }
}
