<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\DTO\ModelPrice;

/**
 * Ціни моделей із config/dwellio.php → ai.pricing. Ціну обираємо один раз на процес
 * (під час резолву клієнта), а не на кожен виклик: модель задається через .env і в рантаймі не змінюється.
 *
 * Невідома платна модель → `fallback` (навмисно дорога): забута ціна в конфігу має
 * швидше вичерпати стелю, ніж мовчки рахувати $0.
 */
final readonly class PriceCatalog
{
    /**
     * @param  array<string, ModelPrice>  $models  ключ — ID моделі
     * @param  list<string>  $freeProviders  локальні провайдери без білінгу (ollama)
     */
    public function __construct(
        private array $models,
        private array $freeProviders,
        private ModelPrice $fallback,
    ) {}

    /** @param  array<array-key, mixed>  $config  вміст dwellio.ai.pricing */
    public static function fromConfig(array $config): self
    {
        $models = [];
        foreach (is_array($config['models'] ?? null) ? $config['models'] : [] as $model => $price) {
            if (is_string($model) && is_array($price)) {
                $models[$model] = self::price($price);
            }
        }

        $free = array_values(array_filter(
            is_array($config['free_providers'] ?? null) ? $config['free_providers'] : [],
            is_string(...),
        ));

        return new self($models, $free, self::price(is_array($config['fallback'] ?? null) ? $config['fallback'] : []));
    }

    public function resolve(string $driver, string $provider, string $model): ModelPrice
    {
        if ($driver === 'fake' || in_array($provider, $this->freeProviders, true)) {
            return ModelPrice::free();
        }

        return $this->models[$model] ?? $this->fallback;
    }

    /** Чи є для платної моделі явна ціна (інакше застосовано fallback). */
    public function knows(string $driver, string $provider, string $model): bool
    {
        return $driver === 'fake'
            || in_array($provider, $this->freeProviders, true)
            || isset($this->models[$model]);
    }

    /** @param  array<array-key, mixed>  $price */
    private static function price(array $price): ModelPrice
    {
        return new ModelPrice(
            inputPerMillion: is_numeric($price['input'] ?? null) ? (float) $price['input'] : 0.0,
            outputPerMillion: is_numeric($price['output'] ?? null) ? (float) $price['output'] : 0.0,
        );
    }
}
