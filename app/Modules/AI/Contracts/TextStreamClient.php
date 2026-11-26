<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTO\TextStreamResult;
use App\Modules\AI\Exceptions\TextStreamFailedException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use Generator;

/**
 * Потокова генерація тексту (ADR-004). Генератор віддає фрагменти тексту в міру надходження,
 * а після завершення `getReturn()` повертає підсумок: модель, причину зупинки й токени.
 *
 * Контракт навмисно не знає про оголошення: промпт збирає викликач (модуль Generation).
 */
interface TextStreamClient
{
    /**
     * @return Generator<int, string, mixed, TextStreamResult>
     *
     * @throws TextStreamFailedException збій провайдера, мережі або обрив посеред стріму
     * @throws TokenBudgetExceededException денний бюджет вичерпано (кидається на першій ітерації)
     */
    public function stream(string $instructions, string $prompt, int $maxTokens): Generator;
}
