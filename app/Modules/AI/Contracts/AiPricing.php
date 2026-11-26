<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\Enums\AiPurpose;

/**
 * Лише читання: чи коштують виклики цілі грошей. Окремо від TokenBudget (ISP): службовим
 * командам (напр. `search:eval`) треба знати ціну, але не можна вміти записувати витрати —
 * записувати їх мають право лише Budget-декоратори (Architecture test).
 */
interface AiPricing
{
    /** false для Ollama / Fake (і будь-якого провайдера з `dwellio.ai.pricing.free_providers`). */
    public function isPaid(AiPurpose $purpose): bool;
}
