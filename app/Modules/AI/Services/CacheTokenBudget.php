<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\DTO\TokenUsage;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Лічильники `ai:tokens:{Y-m-d}:{purpose}:{input|output|calls}` у сховищі кешу (Redis у dev/prod,
 * array у тестах). День рахуємо в UTC: так само рахують AWS Budgets і білінг провайдерів.
 *
 * Чому окремі ключі, а не Redis HASH: Cache::increment — це атомарний INCRBY і він працює
 * на будь-якому драйвері, тож тести не потребують живого Redis.
 */
final readonly class CacheTokenBudget implements TokenBudget
{
    /** Ключі живуть довше доби, щоб `ai:usage --date=вчора` ще бачив учорашні цифри. */
    private const int KEY_TTL_SECONDS = 3 * 86400;

    /**
     * @param  array<string, int>  $dailyLimits  ключ — AiPurpose::value
     */
    public function __construct(
        private Repository $cache,
        private array $dailyLimits,
    ) {}

    public function ensureAvailable(AiPurpose $purpose): void
    {
        if ($this->usage($purpose)->exhausted()) {
            $now = CarbonImmutable::now('UTC');

            throw new TokenBudgetExceededException(
                $purpose,
                max(1, (int) $now->diffInSeconds($now->addDay()->startOfDay())),
            );
        }
    }

    public function record(AiPurpose $purpose, int $inputTokens, int $outputTokens): void
    {
        $this->increment($purpose, 'input', max(0, $inputTokens));
        $this->increment($purpose, 'output', max(0, $outputTokens));
        $this->increment($purpose, 'calls', 1);
    }

    public function usage(AiPurpose $purpose, ?string $date = null): TokenUsage
    {
        $date ??= self::today();

        return new TokenUsage(
            purpose: $purpose,
            date: $date,
            inputTokens: $this->read($purpose, 'input', $date),
            outputTokens: $this->read($purpose, 'output', $date),
            calls: $this->read($purpose, 'calls', $date),
            dailyLimit: $this->dailyLimits[$purpose->value] ?? 0,
        );
    }

    private function increment(AiPurpose $purpose, string $field, int $by): void
    {
        $key = self::key($purpose, $field, self::today());

        // add = SET NX EX: TTL ставиться один раз і лише якщо ключа ще немає; INCRBY TTL не чіпає.
        $this->cache->add($key, 0, self::KEY_TTL_SECONDS);

        if ($by > 0) {
            $this->cache->increment($key, $by);
        }
    }

    private function read(AiPurpose $purpose, string $field, string $date): int
    {
        $value = $this->cache->get(self::key($purpose, $field, $date));

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function key(AiPurpose $purpose, string $field, string $date): string
    {
        return sprintf('ai:tokens:%s:%s:%s', $date, $purpose->value, $field);
    }

    private static function today(): string
    {
        return CarbonImmutable::now('UTC')->toDateString();
    }
}
