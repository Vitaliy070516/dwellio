<?php

declare(strict_types=1);

namespace App\Modules\AI\Enums;

/**
 * На що витрачено токени. Окремий бюджет на кожну ціль: якщо фонова переіндексація
 * з'їла свій ліміт, пошук і генерація продовжують працювати (bulkhead для грошей).
 */
enum AiPurpose: string
{
    /** Ембединги квартир і пошукових запитів. */
    case Embeddings = 'embeddings';
    /** Structured Output: пошуковий запит → фільтри. */
    case QueryParsing = 'query_parsing';
    /** Стрімінг оголошень OLX / rieltor.ua. */
    case Generation = 'generation';
}
