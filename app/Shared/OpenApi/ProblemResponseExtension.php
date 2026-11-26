<?php

declare(strict_types=1);

namespace App\Shared\OpenApi;

use App\Shared\Http\Problems\ApiProblem;
use App\Shared\Http\Problems\ErrorCode;
use App\Shared\Http\Problems\ProblemRenderer;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\EnumCaseType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Документує помилки так, як їх реально віддає ProblemRenderer: `application/problem+json`
 * зі схемою `Problem` (і `ValidationProblem` для 422), а не стандартний для Scramble `{message}`.
 *
 * Scramble вибирає останнє зареєстроване розширення, що підходить, тож це перекриває вбудовані.
 * Для `throw new ApiProblem(ErrorCode::X, '…')` код і текст беремо з виведених типів аргументів:
 * у специфікації з'являється `code: {const: "x"}` і приклад `detail` саме для цього ендпоінту.
 */
final class ProblemResponseExtension extends ExceptionToResponseExtension
{
    public const string PROBLEM_SCHEMA = 'Problem';

    public const string VALIDATION_SCHEMA = 'ValidationProblem';

    public function shouldHandle(Type $type): bool
    {
        if (! $type instanceof ObjectType) {
            return false;
        }

        foreach ([HttpException::class, ValidationException::class, AuthenticationException::class, AuthorizationException::class, RecordsNotFoundException::class] as $class) {
            if ($type->isInstanceOf($class)) {
                return true;
            }
        }

        return false;
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        if ($type->isInstanceOf(ValidationException::class)) {
            return Response::make(422)
                ->setDescription('Validation failed (`code`: `validation_failed`)')
                ->setContent(ProblemRenderer::CONTENT_TYPE, new Reference('schemas', self::VALIDATION_SCHEMA, $this->components));
        }

        [$code, $detail] = $type->isInstanceOf(ApiProblem::class)
            ? $this->apiProblem($type)
            : [$this->frameworkCode($type), $this->literalMessage($type)];

        if ($code === null) {
            return null;
        }

        $specific = new OpenApi\ObjectType;
        $specific->addProperty('code', (new OpenApi\StringType)->const($code->value));
        if ($detail !== null) {
            $specific->addProperty('detail', (new OpenApi\StringType)->examples([$detail]));
        }

        $schema = new AllOf;
        $schema->setItems([new Reference('schemas', self::PROBLEM_SCHEMA, $this->components), $specific]);

        $response = Response::make($code->status())
            ->setDescription(sprintf('`%s`', $code->value))
            ->setContent(ProblemRenderer::CONTENT_TYPE, OpenApiSchemas::of($schema));

        if ($code->hasRetryAfter()) {
            $response->addHeader('Retry-After', new Header(
                // Не required: Scramble зливає відповіді з однаковим статусом (503 queue_unavailable
                // заголовка не має), тож обов'язковість описано текстом.
                description: 'Секунди до повтору. Завжди є для `rate_limited` і `ai_budget_exhausted`.',
                schema: OpenApiSchemas::of(new OpenApi\IntegerType),
            ));
        }

        return $response;
    }

    /** @return array{ErrorCode|null, string|null} */
    private function apiProblem(ObjectType $type): array
    {
        // Scramble виводить `new ApiProblem(ErrorCode::X, '…')` як Generic: promoted-властивість
        // `errorCode` стає EnumCaseType, `detail` — літеральним рядком (тому обидва promoted, а не лише
        // аргументи для батьківського конструктора: там літерал губиться).
        // Нелітеральний код (змінна) → документувати нічого конкретного.
        $codeType = $type->getPropertyType('errorCode');
        $detailType = $type->getPropertyType('detail');

        $code = $codeType instanceof EnumCaseType && $codeType->name === ErrorCode::class
            ? constant(ErrorCode::class.'::'.$codeType->caseName)
            : null;

        return [
            $code instanceof ErrorCode ? $code : null,
            $detailType instanceof LiteralStringType && $detailType->value !== '' ? $detailType->value : null,
        ];
    }

    private function frameworkCode(ObjectType $type): ?ErrorCode
    {
        // abort(409, '…') → HttpException із літеральним статусом у templateTypes (див. вбудоване розширення).
        $templates = $type instanceof Generic ? $type->templateTypes : [];
        $statusType = count($templates) > 3 ? ($templates[7] ?? null) : ($templates[0] ?? null);
        if ($statusType instanceof LiteralIntegerType) {
            return ErrorCode::forStatus($statusType->value);
        }

        return match (true) {
            $type->isInstanceOf(NotFoundHttpException::class), $type->isInstanceOf(RecordsNotFoundException::class) => ErrorCode::NotFound,
            $type->isInstanceOf(TooManyRequestsHttpException::class) => ErrorCode::RateLimited,
            $type->isInstanceOf(AuthenticationException::class), $type->isInstanceOf(UnauthorizedHttpException::class) => ErrorCode::Unauthenticated,
            $type->isInstanceOf(AuthorizationException::class), $type->isInstanceOf(AccessDeniedHttpException::class) => ErrorCode::Forbidden,
            default => null,
        };
    }

    private function literalMessage(ObjectType $type): ?string
    {
        $messageType = $type instanceof Generic ? ($type->templateTypes[1] ?? null) : null;

        return $messageType instanceof LiteralStringType ? $messageType->value : null;
    }
}
