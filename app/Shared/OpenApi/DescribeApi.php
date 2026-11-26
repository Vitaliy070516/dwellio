<?php

declare(strict_types=1);

namespace App\Shared\OpenApi;

use App\Shared\Http\Problems\ErrorCode;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types as T;

/**
 * Document transformer Scramble: те, що не виводиться з коду контролерів.
 *
 * - `servers: [/api]` — відносний шлях замість `url()`: специфікація однакова в CI, локально
 *   і за тунелем, інакше CI-перевірка «закомічене = згенероване» залежала б від APP_URL.
 * - Схеми `Problem` / `ValidationProblem` (RFC 9457), на які посилаються всі помилки.
 */
final class DescribeApi
{
    public function __invoke(OpenApi $openApi): void
    {
        $openApi->servers = [new Server('/api')];

        $openApi->components->addSchema(ProblemResponseExtension::PROBLEM_SCHEMA, OpenApiSchemas::of(self::problem()));

        $fieldErrors = new T\ArrayType;
        $fieldErrors->setItems(new T\StringType);
        $errors = new T\ObjectType;
        $errors->additionalProperties($fieldErrors);
        $errors->setDescription('Помилки по полях: `{"price_max": ["The price max field must be an integer."]}`.');

        $validation = new T\ObjectType;
        $validation->addProperty('code', (new T\StringType)->const(ErrorCode::ValidationFailed->value));
        $validation->addProperty('errors', $errors);
        $validation->setRequired(['errors']);

        $schema = new AllOf;
        $schema->setItems([new Reference('schemas', ProblemResponseExtension::PROBLEM_SCHEMA, $openApi->components), $validation]);
        $openApi->components->addSchema(ProblemResponseExtension::VALIDATION_SCHEMA, OpenApiSchemas::of($schema));
    }

    private static function problem(): T\ObjectType
    {
        $codes = array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases());

        $problem = new T\ObjectType;
        $problem->setDescription('Problem Details (RFC 9457), `Content-Type: application/problem+json`. Клієнт розгалужується за `code`.');
        $problem->addProperty('type', (new T\StringType)->setDescription('URN типу проблеми.')->examples(['urn:dwellio:problem:not_found']));
        $problem->addProperty('title', (new T\StringType)->setDescription('Назва HTTP-статусу.')->examples(['Not Found']));
        $problem->addProperty('status', (new T\IntegerType)->setDescription('Дублює HTTP-статус відповіді.')->examples([404]));
        $problem->addProperty('detail', (new T\StringType)->setDescription('Пояснення для людини; не для розбору кодом.'));
        $problem->addProperty('code', (new T\StringType)->enum($codes)->setDescription('Машиночитний код помилки.'));
        $problem->setRequired(['type', 'title', 'status', 'detail', 'code']);

        return $problem;
    }
}
