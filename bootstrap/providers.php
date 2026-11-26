<?php

declare(strict_types=1);

use App\Modules\AI\AIServiceProvider;
use App\Modules\Generation\GenerationServiceProvider;
use App\Modules\Search\SearchServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AIServiceProvider::class,
    SearchServiceProvider::class,
    GenerationServiceProvider::class,
];
