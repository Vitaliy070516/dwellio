<?php

declare(strict_types=1);

namespace App\Modules\Listings\Enums;

/**
 * Десять адміністративних районів Києва. У БД зберігається slug (varchar),
 * а не native enum Postgres — його простіше змінювати міграціями.
 */
enum District: string
{
    case Holosiivskyi = 'holosiivskyi';
    case Darnytskyi = 'darnytskyi';
    case Desnianskyi = 'desnianskyi';
    case Dniprovskyi = 'dniprovskyi';
    case Obolonskyi = 'obolonskyi';
    case Pecherskyi = 'pecherskyi';
    case Podilskyi = 'podilskyi';
    case Sviatoshynskyi = 'sviatoshynskyi';
    case Solomianskyi = 'solomianskyi';
    case Shevchenkivskyi = 'shevchenkivskyi';

    public function label(): string
    {
        return match ($this) {
            self::Holosiivskyi => 'Голосіївський',
            self::Darnytskyi => 'Дарницький',
            self::Desnianskyi => 'Деснянський',
            self::Dniprovskyi => 'Дніпровський',
            self::Obolonskyi => 'Оболонський',
            self::Pecherskyi => 'Печерський',
            self::Podilskyi => 'Подільський',
            self::Sviatoshynskyi => 'Святошинський',
            self::Solomianskyi => "Солом'янський",
            self::Shevchenkivskyi => 'Шевченківський',
        };
    }
}
