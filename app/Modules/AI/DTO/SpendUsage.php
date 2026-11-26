<?php

declare(strict_types=1);

namespace App\Modules\AI\DTO;

/** Сумарна вартість AI-викликів (усі цілі разом) проти денної та місячної стелі в мікродоларах. */
final readonly class SpendUsage
{
    public function __construct(
        public string $date,
        public string $month,
        public int $dailyMicroUsd,
        public int $dailyCapMicroUsd,
        public int $monthlyMicroUsd,
        public int $monthlyCapMicroUsd,
    ) {}

    public function dailyExhausted(): bool
    {
        return $this->dailyMicroUsd >= $this->dailyCapMicroUsd;
    }

    public function monthlyExhausted(): bool
    {
        return $this->monthlyMicroUsd >= $this->monthlyCapMicroUsd;
    }
}
