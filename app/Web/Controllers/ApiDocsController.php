<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Swagger UI на `/docs/api` і специфікація на `/docs/api.json`.
 *
 * Віддаємо закомічений `docs/openapi.json`, а не генеруємо Scramble-ом на кожен запит:
 * розбір коду коштує секунди CPU і десятки МБ пам'яті php-fpm воркера (на t3.micro це відчутно),
 * а CI вже перевірив, що файл збігається з кодом цього коміту. Генерація — `make openapi`.
 */
final class ApiDocsController extends Controller
{
    public const string SPEC_PATH = 'docs/openapi.json';

    public function ui(): View
    {
        return view('web.api-docs');
    }

    public function spec(): BinaryFileResponse
    {
        $path = base_path(self::SPEC_PATH);
        abort_unless(is_file($path), 404);

        // file(): Last-Modified + 304 на If-Modified-Since — повторне відкриття сторінки без тіла.
        return response()->file($path, ['Content-Type' => 'application/json']);
    }
}
