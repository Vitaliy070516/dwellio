<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * HTTP-помилка з машиночитним кодом. Кидає її лише HTTP-шар (контролер), перекладаючи доменний
 * виняток модуля: самі доменні винятки про HTTP не знають (їх ловлять і Job-и, і консольні команди).
 *
 * Статус виводиться з коду (`ErrorCode::status()`), щоб пара «код ↔ статус» була одна на весь API.
 * Наслідує HttpException: фреймворк не логує її як збій (це очікувана відповідь, а не баг),
 * а Scramble бачить `throw` у контролері і документує відповідь.
 */
final class ApiProblem extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly string $detail,
        array $headers = [],
    ) {
        parent::__construct($errorCode->status(), $detail, null, $headers);
    }
}
