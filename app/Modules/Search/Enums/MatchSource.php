<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/** Гілка hybrid search, яка знайшла квартиру. Значення йдуть у SQL як константні літерали й у JSON-відповідь. */
enum MatchSource: string
{
    /** Косинусна близькість ембедингів (pgvector, HNSW): «за змістом». */
    case Vector = 'vector';

    /** Повнотекстовий пошук `simple`: точний збіг слів. */
    case FullText = 'fts';

    /** pg_trgm word_similarity: відмінки, одруківки, частини слів. */
    case Trigram = 'trigram';
}
