<?php

declare(strict_types=1);

namespace App\Modules\AI\DTO;

/**
 * Результат векторизації. `model` зберігаємо поруч із вектором (listing_embeddings.embedding_model),
 * бо вектори різних моделей між собою непорівнянні.
 */
final readonly class Embedding
{
    /**
     * @param  list<float>  $vector
     */
    public function __construct(
        public array $vector,
        public string $model,
        public int $inputTokens = 0,
    ) {}

    public function dimensions(): int
    {
        return count($this->vector);
    }

    /** Літерал для pgvector: `[0.1,-0.2,...]` (передаємо параметром із кастом `?::vector`). */
    public function toPgVector(): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $this->vector)).']';
    }
}
