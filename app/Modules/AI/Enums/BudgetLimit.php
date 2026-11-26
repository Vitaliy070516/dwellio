<?php

declare(strict_types=1);

namespace App\Modules\AI\Enums;

/** Яка саме стеля спрацювала. Від неї залежить Retry-After і текст для клієнта. */
enum BudgetLimit: string
{
    /** Денний ліміт токенів однієї цілі (bulkhead між цілями). */
    case DailyTokens = 'daily_tokens';
    /** Спільна денна стеля в $ для всіх платних викликів. */
    case DailySpend = 'daily_spend';
    /** Спільна місячна стеля в $ — головний FinOps-запобіжник. */
    case MonthlySpend = 'monthly_spend';
}
