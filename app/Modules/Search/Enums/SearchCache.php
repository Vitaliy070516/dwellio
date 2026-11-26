<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/** Кеші на гарячому шляху пошуку: кожен влучний запит = $0 і мінус секунда латентності. */
enum SearchCache: string
{
    /** Розбір запиту LLM у фільтри (ParseSearchQuery). */
    case ParsedQuery = 'parsed_query';

    /** Вектор семантичного залишку (SearchListings::queryVector). */
    case QueryEmbedding = 'query_embedding';
}
