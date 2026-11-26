<?php

declare(strict_types=1);

namespace App\Shared\OpenApi;

use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\Type;
use LogicException;

/**
 * Fluent-API генератора Scramble не має return types (PHPStan бачить mixed), тож методи-білдери
 * викликаємо окремими інструкціями, а `Schema::fromType()` звужуємо тут один раз — з перевіркою
 * під час виконання, а не з `@var`/ignore.
 */
final class OpenApiSchemas
{
    public static function of(Type $type): Schema
    {
        $schema = Schema::fromType($type);
        if (! $schema instanceof Schema) {
            throw new LogicException('Scramble returned a non-Schema from Schema::fromType().');
        }

        return $schema;
    }
}
