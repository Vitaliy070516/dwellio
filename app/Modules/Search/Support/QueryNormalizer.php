<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use Normalizer;

/**
 * Канонічна форма запиту ДЛЯ КЛЮЧА КЕШУ (у LLM і ембединг іде оригінальний текст).
 *
 * «Тихо, біля метро!» і «тихо біля метро» — один запит, другий не має платити за токени.
 * Нормалізуємо лише те, що не змінює змісту: регістр, Unicode-форму, різновиди апострофа й тире,
 * пунктуацію, пробіли. Порядок слів і числа не чіпаємо: «20 000» ≠ «20000» лишається промахом.
 *
 * Асиметрія ризиків: зайвий промах коштує соту частку цента, а хибне влучання віддає
 * чужі фільтри («1-2 кімнати» → «1 2 кімнати» могло б розібратися інакше). Тому дефіс,
 * апостроф і слеш усередині слова/числа зберігаються.
 */
final class QueryNormalizer
{
    public static function forCacheKey(string $query): string
    {
        // NFKC: «ﬁ» → «fi», повноширинні цифри → звичайні, складені символи → одна форма.
        $text = Normalizer::normalize($query, Normalizer::FORM_KC);
        $text = mb_strtolower(is_string($text) ? $text : $query);

        // Український апостроф набирають щонайменше п'ятьма способами: «п'ять», «п’ять», «пʼять»...
        $text = (string) preg_replace(['/[’‘ʼ`´]/u', '/\p{Pd}/u'], ["'", '-'], $text);

        // Пунктуація → пробіл, крім ' - / між літерами чи цифрами («п'ять», «1-2», «2/3»).
        $text = (string) preg_replace(
            '/[^\P{P}\'\/-]|(?<![\p{L}\p{N}])[\'\/-]|[\'\/-](?![\p{L}\p{N}])/u',
            ' ',
            $text,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function hash(string $query): string
    {
        return hash('sha256', self::forCacheKey($query));
    }
}
