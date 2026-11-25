<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\Exceptions\StructuredOutputFailedException;
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
     * @return array<string, mixed> відповідь моделі; НЕ довіряємо їй, викликач валідує сам
     *
     * @throws StructuredOutputFailedException
     */
    public function generate(string $instructions, string $prompt, Closure $schema): array;
}
