<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Enums\BudgetLimit;
use RuntimeException;

/**
 * Бюджет AI вичерпано: виклик провайдера НЕ відбувся. Повтор до скидання лічильника
 * (опівночі UTC або 1-го числа для місячної стелі) нічого не дасть, тож Job-и не ретраять,
 * а пошук деградує до SQL/FTS.
 */
final class TokenBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly AiPurpose $purpose,
        public readonly int $retryAfterSeconds,
        public readonly BudgetLimit $limit = BudgetLimit::DailyTokens,
    ) {
        parent::__construct(match ($limit) {
            BudgetLimit::DailyTokens => sprintf('Daily AI token budget for "%s" is exhausted.', $purpose->value),
            BudgetLimit::DailySpend => sprintf('Daily AI spend cap is reached (call for "%s" blocked).', $purpose->value),
            BudgetLimit::MonthlySpend => sprintf('Monthly AI spend cap is reached (call for "%s" blocked).', $purpose->value),
        });
    }
}
