<?php

declare(strict_types=1);

namespace App\Modules\AI\Console;

use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Денне споживання токенів і $ проти бюджетів: швидка відповідь на «скільки ми сьогодні витратили». */
final class ShowTokenUsage extends Command
{
    protected $signature = 'ai:usage {--date= : День у UTC (Y-m-d), за замовчуванням сьогодні}';

    protected $description = 'Show AI token usage and spend against the daily and monthly budgets';

    public function handle(TokenBudget $budget): int
    {
        $option = $this->option('date');
        $date = null;

        if (is_string($option) && $option !== '') {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $option, 'UTC')?->toDateString();
            } catch (Throwable) {
                $date = null;
            }

            if ($date !== $option) {
                $this->error('Use --date=YYYY-MM-DD.');

                return self::INVALID;
            }
        }

        $rows = [];
        foreach (AiPurpose::cases() as $purpose) {
            $usage = $budget->usage($purpose, $date);
            $rows[] = [
                $purpose->value,
                number_format($usage->calls),
                number_format($usage->inputTokens),
                number_format($usage->outputTokens),
                number_format($usage->total()),
                number_format($usage->dailyLimit),
                self::percent($usage->total(), $usage->dailyLimit),
                self::usd($usage->costMicroUsd),
            ];
        }

        $spend = $budget->spend($date);

        $this->info(sprintf('AI token usage for %s (UTC)', $spend->date));
        $this->table(['purpose', 'calls', 'input', 'output', 'total', 'daily limit', 'used', 'cost'], $rows);
        $this->table(['spend', 'cost', 'cap', 'used'], [
            ['day '.$spend->date, self::usd($spend->dailyMicroUsd), self::usd($spend->dailyCapMicroUsd), self::percent($spend->dailyMicroUsd, $spend->dailyCapMicroUsd)],
            ['month '.$spend->month, self::usd($spend->monthlyMicroUsd), self::usd($spend->monthlyCapMicroUsd), self::percent($spend->monthlyMicroUsd, $spend->monthlyCapMicroUsd)],
        ]);

        return self::SUCCESS;
    }

    /** 4 знаки після коми: один пошуковий запит на Haiku коштує соті частки цента. */
    private static function usd(int $microUsd): string
    {
        return sprintf('$%.4f', $microUsd / 1_000_000);
    }

    private static function percent(int $used, int $limit): string
    {
        return $limit > 0 ? sprintf('%.1f%%', 100 * $used / $limit) : '—';
    }
}
