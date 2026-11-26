<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use App\Modules\AI\Enums\AiPurpose;
use RuntimeException;

/**
 * Денний бюджет токенів вичерпано: виклик провайдера НЕ відбувся. Повтор до скидання лічильника
 * (опівночі UTC) нічого не дасть, тож Job-и не ретраять, а пошук деградує до SQL/FTS.
 */
final class TokenBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly AiPurpose $purpose,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(sprintf('Daily AI token budget for "%s" is exhausted.', $purpose->value));
    }
}
