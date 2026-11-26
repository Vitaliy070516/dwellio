<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Агент laravel/ai для стрімінгу тексту. Окремий клас, а не `agent()`, бо SDK бере
 * `maxTokens()` / `temperature()` з методів агента (див. TextGenerationOptions::forAgent).
 * Ліміт токенів — це і стеля витрат (FinOps), і захист від нескінченної генерації.
 */
final class TextStreamAgent implements Agent
{
    use Promptable;

    public function __construct(
        private readonly string $instructions,
        private readonly int $maxTokens,
        private readonly float $temperature,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    public function temperature(): float
    {
        return $this->temperature;
    }
}
