<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\DTO\Embedding;
use App\Modules\AI\Enums\AiPurpose;

/**
 * Декоратор: бюджет перевіряється ДО платного виклику, токени дописуються ПІСЛЯ.
 * Обгортає будь-яку реалізацію (адаптер чи Fake), тож облік працює однаково і в проді, і в тестах.
 */
final readonly class BudgetedEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        public EmbeddingClient $inner,
        private TokenBudget $budget,
    ) {}

    public function model(): string
    {
        return $this->inner->model();
    }

    public function embed(string $text): Embedding
    {
        $this->budget->ensureAvailable(AiPurpose::Embeddings);

        $embedding = $this->inner->embed($text);
        $this->budget->record(AiPurpose::Embeddings, $embedding->inputTokens, 0);

        return $embedding;
    }
}
