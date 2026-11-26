<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\OpenApi\DescribeApi;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // У register(), а не в boot(): Scramble реєструє свої маршрути у власному boot(), який
        // виконується раніше за boot() цього провайдера, і /docs/api дістався б йому (403 поза local).
        // UI і JSON специфікації віддаємо самі із закоміченого docs/openapi.json (ApiDocsController):
        // без розбору коду на кожен запит і без різниці між тим, що показуємо, і тим, що перевірив CI.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::afterOpenApiGenerated(new DescribeApi);
        // broadcasting/auth — маршрут фреймворку (GET|POST, протокол Pusher): Scramble описав би його
        // як GET з порожнім 200. Потік авторизації каналу описано текстом в info.description.
        Scramble::routes(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/')
            && $route->uri() !== 'api/broadcasting/auth');
    }
}
