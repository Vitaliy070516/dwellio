<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTO\StructuredOutput;
use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Structured Output (ADR-004): запит до LLM, відповідь якої має відповідати JSON-схемі.
 * Контракт навмисно загальний: AI-модуль не знає про квартири, схему задає викликач
 * (так `Search` не імпортує SDK, а `AI` не залежить від доменних модулів).
 */
interface StructuredOutputClient
{
    /**
     * @param  Closure(JsonSchema): array<string, Type>  $schema
     *
     * @throws StructuredOutputFailedException
     * @throws TokenBudgetExceededException денний бюджет вичерпано, провайдера не викликали
     */
    public function generate(string $instructions, string $prompt, Closure $schema): StructuredOutput;
}
