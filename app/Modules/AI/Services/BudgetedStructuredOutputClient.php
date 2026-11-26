<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\DTO\StructuredOutput;
use App\Modules\AI\Enums\AiPurpose;
use Closure;

/** Декоратор обліку токенів для Structured Output (див. BudgetedEmbeddingClient). */
final readonly class BudgetedStructuredOutputClient implements StructuredOutputClient
{
    public function __construct(
        public StructuredOutputClient $inner,
        private TokenBudget $budget,
    ) {}

    public function generate(string $instructions, string $prompt, Closure $schema): StructuredOutput
    {
        $this->budget->ensureAvailable(AiPurpose::QueryParsing);

        $output = $this->inner->generate($instructions, $prompt, $schema);
        $this->budget->record(AiPurpose::QueryParsing, $output->inputTokens, $output->outputTokens);

        return $output;
    }
}
