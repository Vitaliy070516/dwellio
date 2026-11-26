<?php

declare(strict_types=1);

namespace App\Modules\AI\Console;

use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Денне споживання токенів проти бюджету: швидка відповідь на «скільки ми сьогодні витратили». */
final class ShowTokenUsage extends Command
{
    protected $signature = 'ai:usage {--date= : День у UTC (Y-m-d), за замовчуванням сьогодні}';

    protected $description = 'Show today\'s AI token usage against the daily budgets';

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
                $usage->dailyLimit > 0 ? sprintf('%.1f%%', 100 * $usage->total() / $usage->dailyLimit) : '—',
            ];
        }

        $this->info(sprintf('AI token usage for %s (UTC)', $date ?? CarbonImmutable::now('UTC')->toDateString()));
        $this->table(['purpose', 'calls', 'input', 'output', 'total', 'daily limit', 'used'], $rows);

        return self::SUCCESS;
    }
}
