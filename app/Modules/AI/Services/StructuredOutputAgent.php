<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Агент laravel/ai для Structured Output. Окремий клас замість `agent()`: SDK бере
 * `temperature()` / `maxTokens()` лише з методів агента (TextGenerationOptions::forAgent).
 *
 * Temperature 0: це витягування фактів, а не творчість. Без нього той самий запит
 * давав різні фільтри від прогону до прогону, і `search:eval` міряв шум, а не пошук.
 */
final class StructuredOutputAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  Closure(JsonSchema): array<string, Type>  $schema
     */
    public function __construct(
        private readonly string $instructions,
        private readonly Closure $schema,
        private readonly float $temperature,
        private readonly int $maxTokens,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }

    public function temperature(): float
    {
        return $this->temperature;
    }

    /** Стеля відповіді — і FinOps, і захист від «розговорливої» моделі: JSON фільтрів займає ~100 токенів. */
    public function maxTokens(): int
    {
        return $this->maxTokens;
    }
}
