<?php

declare(strict_types=1);

use App\Modules\AI\AIServiceProvider;
use App\Modules\Generation\GenerationServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AIServiceProvider::class,
    GenerationServiceProvider::class,
];
