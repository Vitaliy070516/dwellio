<?php

declare(strict_types=1);

use App\Modules\Search\Support\RankingMetrics;

it('computes recall@k capped by the number of relevant items', function (): void {
    // 2 з 3 релевантних у топ-5.
    expect(RankingMetrics::recallAt([1, 9, 2, 8, 7], [1, 2, 3], 5))->toBe(2 / 3)
        // Релевантних 100, а k = 3: ідеальна видача має давати 1.0, а не 0.03.
        ->and(RankingMetrics::recallAt([1, 2, 3], range(1, 100), 3))->toBe(1.0)
        // Релевантні за межами топ-k не рахуються.
        ->and(RankingMetrics::recallAt([9, 8, 1], [1], 2))->toBe(0.0);
});

it('computes the reciprocal rank of the first relevant item', function (): void {
    expect(RankingMetrics::reciprocalRank([5, 1, 2], [1, 2], 10))->toBe(0.5)
        ->and(RankingMetrics::reciprocalRank([1], [1], 10))->toBe(1.0)
        ->and(RankingMetrics::reciprocalRank([5, 6, 1], [1], 2))->toBe(0.0);
});

it('handles empty inputs without division by zero', function (): void {
    expect(RankingMetrics::recallAt([], [1], 10))->toBe(0.0)
        ->and(RankingMetrics::recallAt([1], [], 10))->toBe(0.0)
        ->and(RankingMetrics::reciprocalRank([], [1], 10))->toBe(0.0)
        ->and(RankingMetrics::mean([]))->toBe(0.0)
        ->and(RankingMetrics::mean([1.0, 0.5]))->toBe(0.75);
});
