<?php

declare(strict_types=1);

namespace App\Shared\OpenApi;

use App\Shared\Http\Middleware\ReadOnlyInDemo;
use App\Shared\Http\Problems\ErrorCode;
use App\Shared\Http\Problems\ProblemRenderer;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * 403 `demo_read_only` для маршрутів з middleware ReadOnlyInDemo. Scramble виводить відповіді з
 * `throw` у контролері, а не в middleware, тож без цього розширення специфікація мовчала б про 403.
 * Відповідь документується завжди (а не лише при DEMO_MODE): специфікація не залежить від оточення.
 */
final class DemoReadOnlyResponse extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array(ReadOnlyInDemo::ALIAS, $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $code = ErrorCode::DemoReadOnly;
        $components = $this->openApiTransformer->getComponents();

        $specific = new OpenApi\ObjectType;
        $specific->addProperty('code', (new OpenApi\StringType)->const($code->value));
        $specific->addProperty('detail', (new OpenApi\StringType)->examples([ReadOnlyInDemo::DETAIL]));

        $schema = new AllOf;
        $schema->setItems([new Reference('schemas', ProblemResponseExtension::PROBLEM_SCHEMA, $components), $specific]);

        $response = Response::make($code->status());
        $response->setDescription(sprintf('`%s` — публічне демо (DEMO_MODE) працює лише на читання', $code->value));
        $response->setContent(ProblemRenderer::CONTENT_TYPE, OpenApiSchemas::of($schema));

        $operation->addResponse($response);
    }
}
