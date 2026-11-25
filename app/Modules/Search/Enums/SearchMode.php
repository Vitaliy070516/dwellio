<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/** Як насправді виконано пошук: клієнт бачить, чи не деградував сервіс. */
enum SearchMode: string
{
    /** Вектор + FTS + trigram, злиті через RRF. */
    case Hybrid = 'hybrid';

    /** Провайдер ембедингів недоступний: лише FTS + trigram. */
    case TextOnly = 'text_only';

    /** Немає тексту для ранжування (або жодна гілка не працює): лише SQL-фільтри, свіжі спершу. */
    case FiltersOnly = 'filters_only';
}
