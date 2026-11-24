<?php

declare(strict_types=1);

namespace App\Modules\Listings\Enums;

enum EmbedOutcome: string
{
    /** Вектор пораховано й збережено (платний виклик). */
    case Embedded = 'embedded';

    /** Текст не змінився з минулого разу: виклик провайдера пропущено. */
    case Unchanged = 'unchanged';

    /** Квартира не підлягає індексації (чернетка, архів). */
    case Skipped = 'skipped';
}
