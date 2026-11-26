<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\DTO\TextStreamResult;
use App\Modules\AI\Enums\AiPurpose;
use Generator;

/**
 * Декоратор обліку токенів для стріму. Генератор лінивий: перевірка бюджету спрацює
 * на першій ітерації, тобто до відкриття з'єднання з провайдером.
 *
 * Обірваний стрім (виняток посеред потоку) токенів не повертає — провайдер шле usage
 * лише у фінальній події. Такі втрати видно в логах спроб (`outcome` != completed).
 */
final readonly class BudgetedTextStreamClient implements TextStreamClient
{
    public function __construct(
        public TextStreamClient $inner,
        private TokenBudget $budget,
    ) {}

    /** @return Generator<int, string, mixed, TextStreamResult> */
    public function stream(string $instructions, string $prompt, int $maxTokens): Generator
    {
        $this->budget->ensureAvailable(AiPurpose::Generation);

        $result = yield from $this->inner->stream($instructions, $prompt, $maxTokens);
        $this->budget->record(AiPurpose::Generation, $result->inputTokens, $result->outputTokens);

        return $result;
    }
}
