<?php

declare(strict_types=1);

use App\Modules\AI\Services\StructuredOutputAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Gateway\TextGenerationOptions;

it('exposes temperature and max tokens the way the SDK reads them', function (): void {
    $agent = new StructuredOutputAgent(
        'instructions',
        static fn (JsonSchema $schema): array => ['x' => $schema->string()],
        temperature: 0.0,
        maxTokens: 500,
    );

    // Перевіряємо саме те, що SDK передасть провайдеру, а не лише геттери агента.
    $options = TextGenerationOptions::forAgent($agent);

    expect($options->temperature)->toBe(0.0)
        ->and($options->maxTokens)->toBe(500);
});
