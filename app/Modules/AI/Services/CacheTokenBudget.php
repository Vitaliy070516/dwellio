<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\AiPricing;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\DTO\ModelPrice;
use App\Modules\AI\DTO\SpendUsage;
use App\Modules\AI\DTO\TokenUsage;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Enums\BudgetLimit;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Лічильники у сховищі кешу (Redis у dev/prod, array у тестах). День і місяць рахуємо в UTC:
 * так само рахують AWS Budgets і білінг провайдерів.
 *
 * - `ai:tokens:{Y-m-d}:{purpose}:{input|output|calls}` — токени й виклики;
 * - `ai:cost:{Y-m-d}:{purpose}:usd_micros` — вартість за день по цілі;
 * - `ai:cost:{Y-m}:usd_micros` — вартість за місяць (усі цілі).
 *
 * Чому окремі ключі, а не Redis HASH: Cache::increment — це атомарний INCRBY і він працює
 * на будь-якому драйверу, тож тести не потребують живого Redis. Гроші — цілі мікродолари:
 * INCRBYFLOAT накопичує похибку, а INCRBY — ні.
 */
final readonly class CacheTokenBudget implements AiPricing, TokenBudget
{
    /** Ключі живуть довше доби, щоб `ai:usage --date=вчора` ще бачив учорашні цифри. */
    private const int KEY_TTL_SECONDS = 3 * 86400;

    /** Місячний ключ створюється першим викликом місяця: 40 діб покривають будь-який місяць із запасом. */
    private const int MONTH_KEY_TTL_SECONDS = 40 * 86400;

    /**
     * @param  array<string, int>  $dailyLimits  ключ — AiPurpose::value
     * @param  array<string, ModelPrice>  $prices  ключ — AiPurpose::value; немає ціни = безкоштовно
     * @param  int  $dailySpendCapMicroUsd  стеля в $ × 10⁻⁶; перевіряється лише для платних цілей
     */
    public function __construct(
        private Repository $cache,
        private array $dailyLimits,
        private array $prices = [],
        private int $dailySpendCapMicroUsd = 0,
        private int $monthlySpendCapMicroUsd = 0,
    ) {}

    public function ensureAvailable(AiPurpose $purpose): void
    {
        $now = CarbonImmutable::now('UTC');
        $untilMidnight = max(1, (int) $now->diffInSeconds($now->addDay()->startOfDay()));

        if ($this->usage($purpose)->exhausted()) {
            throw new TokenBudgetExceededException($purpose, $untilMidnight);
        }

        // Безкоштовний виклик (Ollama, Fake) грошей не витрачає — блокувати його через $-стелю безглуздо.
        if ($this->price($purpose)->isFree()) {
            return;
        }

        $spend = $this->spend();

        if ($spend->monthlyExhausted()) {
            throw new TokenBudgetExceededException(
                $purpose,
                max(1, (int) $now->diffInSeconds($now->addMonthNoOverflow()->startOfMonth())),
                BudgetLimit::MonthlySpend,
            );
        }

        if ($spend->dailyExhausted()) {
            throw new TokenBudgetExceededException($purpose, $untilMidnight, BudgetLimit::DailySpend);
        }
    }

    public function record(AiPurpose $purpose, int $inputTokens, int $outputTokens): void
    {
        $now = CarbonImmutable::now('UTC');
        $date = $now->toDateString();

        $this->increment(self::tokenKey($purpose, 'input', $date), max(0, $inputTokens), self::KEY_TTL_SECONDS);
        $this->increment(self::tokenKey($purpose, 'output', $date), max(0, $outputTokens), self::KEY_TTL_SECONDS);
        $this->increment(self::tokenKey($purpose, 'calls', $date), 1, self::KEY_TTL_SECONDS);

        $cost = $this->price($purpose)->costMicroUsd($inputTokens, $outputTokens);

        if ($cost > 0) {
            $this->increment(self::costKey($purpose, $date), $cost, self::KEY_TTL_SECONDS);
            $this->increment(self::monthKey($now->format('Y-m')), $cost, self::MONTH_KEY_TTL_SECONDS);
        }
    }

    public function usage(AiPurpose $purpose, ?string $date = null): TokenUsage
    {
        $date ??= CarbonImmutable::now('UTC')->toDateString();

        $values = $this->readMany([
            'input' => self::tokenKey($purpose, 'input', $date),
            'output' => self::tokenKey($purpose, 'output', $date),
            'calls' => self::tokenKey($purpose, 'calls', $date),
            'cost' => self::costKey($purpose, $date),
        ]);

        return new TokenUsage(
            purpose: $purpose,
            date: $date,
            inputTokens: $values['input'],
            outputTokens: $values['output'],
            calls: $values['calls'],
            dailyLimit: $this->dailyLimits[$purpose->value] ?? 0,
            costMicroUsd: $values['cost'],
        );
    }

    public function spend(?string $date = null): SpendUsage
    {
        $date ??= CarbonImmutable::now('UTC')->toDateString();
        $month = substr($date, 0, 7);

        $keys = ['month' => self::monthKey($month)];
        foreach (AiPurpose::cases() as $purpose) {
            $keys[$purpose->value] = self::costKey($purpose, $date);
        }

        // Один MGET на Redis замість N окремих GET: ensureAvailable стоїть на гарячому шляху пошуку.
        $values = $this->readMany($keys);
        $monthly = $values['month'];
        unset($values['month']);

        return new SpendUsage(
            date: $date,
            month: $month,
            dailyMicroUsd: array_sum($values),
            dailyCapMicroUsd: $this->dailySpendCapMicroUsd,
            monthlyMicroUsd: $monthly,
            monthlyCapMicroUsd: $this->monthlySpendCapMicroUsd,
        );
    }

    public function isPaid(AiPurpose $purpose): bool
    {
        return ! $this->price($purpose)->isFree();
    }

    private function price(AiPurpose $purpose): ModelPrice
    {
        return $this->prices[$purpose->value] ?? ModelPrice::free();
    }

    private function increment(string $key, int $by, int $ttl): void
    {
        // add = SET NX EX: TTL ставиться один раз і лише якщо ключа ще немає; INCRBY TTL не чіпає.
        $this->cache->add($key, 0, $ttl);

        if ($by > 0) {
            $this->cache->increment($key, $by);
        }
    }

    /**
     * @template TName of string
     *
     * @param  array<TName, string>  $keys  назва → ключ кешу
     * @return array<TName, int>
     */
    private function readMany(array $keys): array
    {
        $raw = $this->cache->many(array_values($keys));

        $result = [];
        foreach ($keys as $name => $key) {
            $value = $raw[$key] ?? null;
            $result[$name] = is_numeric($value) ? (int) $value : 0;
        }

        return $result;
    }

    private static function tokenKey(AiPurpose $purpose, string $field, string $date): string
    {
        return sprintf('ai:tokens:%s:%s:%s', $date, $purpose->value, $field);
    }

    private static function costKey(AiPurpose $purpose, string $date): string
    {
        return sprintf('ai:cost:%s:%s:usd_micros', $date, $purpose->value);
    }

    private static function monthKey(string $month): string
    {
        return sprintf('ai:cost:%s:usd_micros', $month);
    }
}
