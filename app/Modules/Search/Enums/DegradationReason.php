<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/**
 * Чому AI-крок пошуку не відпрацював. `SearchMode` каже ЩО сталося (як виконано пошук),
 * а це — ЧОМУ: клієнт показує «бюджет вичерпано» інакше, ніж «AI недоступний».
 */
enum DegradationReason: string
{
    /** Провайдер недоступний, таймаут, помилка мережі. */
    case ProviderUnavailable = 'provider_unavailable';

    /** Провайдер відповів, але відповідь не пройшла валідацію (JSON не за схемою, вектор не тієї розмірності). */
    case InvalidOutput = 'invalid_output';

    /** Денний або місячний бюджет AI вичерпано: провайдера навіть не викликали. */
    case BudgetExceeded = 'budget_exceeded';
}
