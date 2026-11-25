<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use Closure;
use Throwable;

use function Laravel\Ai\agent;

/** Адаптер над laravel/ai: ad-hoc агент зі схемою. Провайдер і модель приходять із конфігу. */
final readonly class LaravelAiStructuredOutputClient implements StructuredOutputClient
{
    public function __construct(
        private string $provider,
        private string $model,
        private int $timeout = 30,
    ) {}

    public function generate(string $instructions, string $prompt, Closure $schema): array
    {
        try {
            $response = agent(instructions: $instructions, schema: $schema)
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

        return $structured;
    }
}
