<?php

declare(strict_types=1);

namespace App\Modules\Generation\Enums;

/**
 * Майданчики й їхні обмеження. Ліміти консервативні й НЕ звірені з актуальними правилами
 * OLX / rieltor.ua: перевірити перед реальною публікацією (known issue).
 */
enum Platform: string
{
    case Olx = 'olx';
    case Rieltor = 'rieltor';

    public function label(): string
    {
        return match ($this) {
            self::Olx => 'OLX',
            self::Rieltor => 'rieltor.ua',
        };
    }

    public function titleMaxChars(): int
    {
        return match ($this) {
            self::Olx => 70,
            self::Rieltor => 100,
        };
    }

    public function bodyMinChars(): int
    {
        return 80;
    }

    public function bodyMaxChars(): int
    {
        return match ($this) {
            self::Olx => 3000,
            self::Rieltor => 4000,
        };
    }

    /** Стеля вихідних токенів: обмежує і витрати, і «балакучість» моделі. */
    public function maxTokens(): int
    {
        return match ($this) {
            self::Olx => 900,
            self::Rieltor => 1200,
        };
    }

    public function style(): string
    {
        return match ($this) {
            self::Olx => 'Стиль OLX: живо й коротко, 600–1200 символів. Почни з головної переваги, '
                .'далі 3–6 коротких абзаців або рядків про квартиру, район і умови.',
            self::Rieltor => 'Стиль rieltor.ua: діловий і структурований, 800–1800 символів. '
                .'Окремими абзацами: квартира, будинок і район, транспорт, умови оренди.',
        };
    }
}
