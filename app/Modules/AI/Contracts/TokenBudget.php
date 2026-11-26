<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTO\TokenUsage;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;

/**
 * Облік токенів і денна стеля на кожну ціль (FinOps). Лічильники живуть у Redis:
 * спільні для всіх php-fpm, воркерів і реплік, атомарні (INCRBY) і переживають рестарт процесу.
 *
 * Перевірка м'яка: «перевірив → викликав → дописав». Паралельні виклики можуть перевищити
 * ліміт максимум на (кількість одночасних викликів × max_tokens одного виклику). Для денного
 * запобіжника це прийнятно; жорсткий варіант — резервувати max_tokens до виклику (див. ADR-002).
 */
interface TokenBudget
{
    /** @throws TokenBudgetExceededException */
    public function ensureAvailable(AiPurpose $purpose): void;

    public function record(AiPurpose $purpose, int $inputTokens, int $outputTokens): void;

    /** @param  string|null  $date  `Y-m-d` у UTC; null — сьогодні */
    public function usage(AiPurpose $purpose, ?string $date = null): TokenUsage;
}
