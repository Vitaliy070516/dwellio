<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

/**
 * Офлайн-метрики якості ранжування. Чисті функції: вхід — видача та множина релевантних id.
 *
 * Recall@k рахуємо «з обмеженням»: ділимо на min(|R|, k), а не на |R|. Інакше запит
 * «квартира з меблями» (релевантних ~190) мав би стелю 10/190 = 5% навіть при ідеальній
 * видачі, і середнє по golden set показувало б розмір множин, а не якість пошуку.
 */
final class RankingMetrics
{
    /**
     * @param  list<int>  $ranked  id у порядку видачі
     * @param  list<int>  $relevant
     */
    public static function recallAt(array $ranked, array $relevant, int $k): float
    {
        if ($relevant === [] || $k < 1) {
            return 0.0;
        }

        $found = count(array_intersect(array_slice($ranked, 0, $k), $relevant));

        return $found / min(count($relevant), $k);
    }

    /**
     * Reciprocal rank: 1 / позиція першого релевантного в топ-k; 0, якщо в топ-k його немає.
     * Середнє по запитах = MRR: «як далеко користувачу гортати до першої влучної квартири».
     *
     * @param  list<int>  $ranked
     * @param  list<int>  $relevant
     */
    public static function reciprocalRank(array $ranked, array $relevant, int $k): float
    {
        $relevantSet = array_flip($relevant);

        foreach (array_slice($ranked, 0, $k) as $position => $id) {
            if (isset($relevantSet[$id])) {
                return 1 / ($position + 1);
            }
        }

        return 0.0;
    }

    /** @param  list<float>  $values */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }
}
