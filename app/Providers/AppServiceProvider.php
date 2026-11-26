<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Demo\DemoMode;
use App\Shared\OpenApi\DescribeApi;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
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

        // Захист від забутого APP_DEBUG=true у .env: на публічному демо стек-трейс, SQL і шляхи
        // назовні не віддаємо ніколи (ProblemRenderer і сторінки помилок читають app.debug під час рендеру).
        if (DemoMode::enabled()) {
            config(['app.debug' => false]);
        }

        // Sail запускає сайт через `artisan serve`, а той передає воркерам PHP лише білий список змінних
        // оточення. Без цього make demo-up змінив би CLI (config:cache, demo:check), а сайт лишився б
        // на .env: не demo, з APP_DEBUG і без кешу конфігу — чеклист зелений, а назовні стек-трейси.
        ServeCommand::$passthroughVariables = array_values(array_unique([
            ...ServeCommand::$passthroughVariables,
            'APP_DEBUG', 'APP_CONFIG_CACHE', 'DEMO_MODE', 'TRUSTED_PROXIES',
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Нечисловий id у URL — 404 від маршрутизатора, а не 500 від Postgres (`bigint = 'abc'`)
        // з SQL у тексті помилки при APP_DEBUG. Для генерацій id — UUID.
        RouteFacade::pattern('listing', '[0-9]+');
        RouteFacade::pattern('ad', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

        Scramble::afterOpenApiGenerated(new DescribeApi);
        // broadcasting/auth — маршрут фреймворку (GET|POST, протокол Pusher): Scramble описав би його
        // як GET з порожнім 200. Потік авторизації каналу описано текстом в info.description.
        Scramble::routes(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/')
            && $route->uri() !== 'api/broadcasting/auth');
    }
}
