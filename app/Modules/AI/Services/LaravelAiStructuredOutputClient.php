<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\DTO\StructuredOutput;
use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use Closure;
use Throwable;

/** Адаптер над laravel/ai: ad-hoc агент зі схемою. Провайдер і модель приходять із конфігу. */
final readonly class LaravelAiStructuredOutputClient implements StructuredOutputClient
{
    public function __construct(
        private string $provider,
        private string $model,
        private int $timeout = 30,
        private float $temperature = 0.0,
        private int $maxTokens = 500,
    ) {}

    public function generate(string $instructions, string $prompt, Closure $schema): StructuredOutput
    {
        try {
            $response = (new StructuredOutputAgent($instructions, $schema, $this->temperature, $this->maxTokens))
                ->prompt($prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);
        } catch (Throwable $e) {
            throw new StructuredOutputFailedException(
                sprintf('LLM provider "%s" failed: %s', $this->provider, $e->getMessage()),
                previous: $e,
            );
        }

        // Ключі JSON-обʼєкта завжди рядки; нормалізуємо, щоб контракт був чесним для типів.
        $structured = [];
        foreach ($response->toArray() as $key => $value) {
            $structured[(string) $key] = $value;
        }

        return new StructuredOutput(
            $structured,
            $response->meta->model ?? $this->model,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
        );
    }
}
