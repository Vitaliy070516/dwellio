<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

/**
 * Друга лінія захисту від галюцинацій LLM-парсера: фільтр приймається, лише якщо
 * в тексті запиту є на що спертися (grounding). Валідатор перевіряє ТИП («district — валідний slug»),
 * а тут перевіряється ЗМІСТ («користувач справді згадав Шевченківський район»).
 *
 * Навіщо: `search:eval` показав, що qwen2.5:3b додає district/rooms у запити, де про них ні слова,
 * а «до 15 тисяч» перетворює на price_min. Вигаданий жорсткий фільтр гірший за пропущений:
 * він мовчки викидає з видачі все релевантне, а пропущений лише трохи погіршує ранжування.
 *
 * Правила навмисно ліберальні (основи слів, а не точні форми): хибно відкинутий фільтр
 * повертає запит до семантичного пошуку, тож ціна помилки тут низька.
 */
final class FilterGrounding
{
    /** Основи назв районів (укр./рос., після QueryNormalizer: нижній регістр, апостроф '). */
    private const array DISTRICT_STEMS = [
        'holosiivskyi' => ['голосі', 'голосеев'],
        'darnytskyi' => ['дарниц', 'позняк', 'осокорк'],
        'desnianskyi' => ['деснян', 'троєщ', 'троещ'],
        'dniprovskyi' => ['дніпровськ', 'днепровск', 'русанів', 'русанов', 'лівобережн', 'левобережн'],
        'obolonskyi' => ['оболон'],
        'pecherskyi' => ['печер', 'липк'],
        'podilskyi' => ['поділ', 'подол', 'подільськ'],
        'sviatoshynskyi' => ['святошин', 'академмістечк', 'академгородк'],
        'solomianskyi' => ["солом'ян", 'соломен'],
        'shevchenkivskyi' => ['шевченк', "лук'янівк", 'лукьяновк'],
    ];

    /** Збірні назви, що покривають кілька районів. */
    private const array AREA_STEMS = [
        // «берег» / «на березі»: у місцевому відмінку г → з.
        'лів(ий|ому|ого|им) бер(ег|ез)|лів(ий|ому) бік|лев(ый|ом|ого) бер(ег|ез)' => ['dniprovskyi', 'darnytskyi', 'desnianskyi'],
        // «в центрі», «центр міста», але не «торговий центр поруч».
        '(в|у|біля|до) центр|центр\p{L}* (міста|києва|города)|центральн\p{L}* район' => ['pecherskyi', 'shevchenkivskyi'],
    ];

    /** Без межі слова навмисно: основа стоїть усередині («дво-кімн-атна», «одно-комн-атная»). */
    private const string ROOMS = '/кімн|комн|однушк|двушк|трешк|трьошк|студі|студи|room/u';

    /*
     * Решта шаблонів — лише з початку слова (?<!\p{L}): інакше «cat» знаходиться в «location»,
     * а «кат» — у «делікатний», і вигаданий фільтр проходить перевірку.
     */
    private const string METRO = '/(?<!\p{L})(?:метро|metro|станці|станци|хвилин|хв(?!\p{L})|пішки|пешком|минут|walk)/u';

    /** «кат» — найчастіша одруківка «кот» («з катом»): відкинути справжній фільтр через одну літеру гірше. */
    private const string PETS = '/(?<!\p{L})(?:кот|кіт|кат|кіш|кош|киц|соба|пес|цуцен|тварин|животн|улюблен|pet|dog|cat)/u';

    private const string FURNITURE = '/(?<!\p{L})(?:мебл|мебел|техні|техни|обстав|furnish|appliance)/u';

    private const string PRICE_FROM = '/(^|\s)(від|от|from)\s/u';

    private const string PRICE_TO = '/(^|\s)(до|under|max)\s/u';

    /**
     * @param  array<string, mixed>  $valid  вихід LLM після валідатора
     * @return array{0: array<string, mixed>, 1: list<string>} очищені фільтри й назви відкинутих полів (для логу)
     */
    public static function apply(array $valid, string $query): array
    {
        $text = QueryNormalizer::forCacheKey($query);
        $dropped = [];

        $districts = is_array($valid['districts'] ?? null) ? $valid['districts'] : [];
        if ($districts !== []) {
            $grounded = self::groundedDistricts($text);
            $kept = array_values(array_filter($districts, static fn (mixed $slug): bool => is_string($slug) && in_array($slug, $grounded, true)));
            if (count($kept) !== count($districts)) {
                $dropped[] = 'districts';
            }
            $valid['districts'] = $kept;
        }

        if (is_array($valid['rooms'] ?? null) && $valid['rooms'] !== [] && preg_match(self::ROOMS, $text) !== 1) {
            $valid['rooms'] = [];
            $dropped[] = 'rooms';
        }

        if (($valid['max_metro_distance_m'] ?? null) !== null && preg_match(self::METRO, $text) !== 1) {
            $valid['max_metro_distance_m'] = null;
            $dropped[] = 'max_metro_distance_m';
        }

        if (($valid['pets_allowed'] ?? null) !== null && preg_match(self::PETS, $text) !== 1) {
            $valid['pets_allowed'] = null;
            $dropped[] = 'pets_allowed';
        }

        if (($valid['furnished'] ?? null) !== null && preg_match(self::FURNITURE, $text) !== 1) {
            $valid['furnished'] = null;
            $dropped[] = 'furnished';
        }

        $valid = self::groundPrice($valid, $text, $dropped);

        return [$valid, array_values(array_unique($dropped))];
    }

    /**
     * Ціна без відповідного числа в запиті — вигадка («недорого» ≠ 12 000). Напрям береться зі слів
     * «від» / «до»: модель, яка зробила з «до 15 тисяч» price_min, отримає price_max.
     *
     * @param  array<string, mixed>  $valid
     * @param  list<string>  $dropped
     * @return array<string, mixed>
     */
    private static function groundPrice(array $valid, string $text, array &$dropped): array
    {
        $min = $valid['price_min'] ?? null;
        $max = $valid['price_max'] ?? null;

        if ($min === null && $max === null) {
            return $valid;
        }

        // Межа має збігатися з числом із запиту: X або X × 1000 («до 25 тисяч», «20k»).
        // Інакше «5 хвилин пішки до метро» перетворюється на price_max = 500.
        $numbers = self::numbers($text);
        foreach (['price_min', 'price_max'] as $field) {
            // Валідатор `integer` пропускає й рядок "20000": зводимо до int, щоб порівняння нижче були чесними.
            $value = is_numeric($valid[$field] ?? null) ? (int) $valid[$field] : null;
            $grounded = $value !== null && (in_array($value, $numbers, true)
                || ($value % 1000 === 0 && in_array(intdiv($value, 1000), $numbers, true)));

            if ($value !== null && ! $grounded) {
                $dropped[] = $field;
            }
            $valid[$field] = $grounded ? $value : null;
        }
        $min = $valid['price_min'] ?? null;
        $max = $valid['price_max'] ?? null;

        if ($min === null && $max === null) {
            return $valid;
        }

        $saysFrom = preg_match(self::PRICE_FROM, $text) === 1;
        $saysTo = preg_match(self::PRICE_TO, $text) === 1;

        // «до 15 тисяч» → модель поставила min = max = 15000 або лише min: лишаємо тільки верхню межу.
        if ($min !== null && ($min === $max || $max === null) && $saysTo && ! $saysFrom) {
            $valid['price_min'] = null;
            $valid['price_max'] = $max ?? $min;
            $dropped[] = 'price_min';
        } elseif ($max !== null && ($min === $max || $min === null) && $saysFrom && ! $saysTo) {
            $valid['price_max'] = null;
            $valid['price_min'] = $min ?? $max;
            $dropped[] = 'price_max';
        }

        return $valid;
    }

    /**
     * Числа із запиту. Групи розрядів зливаються: «20 000» → 20000 (а «20» лишається й окремо).
     *
     * @return list<int>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d+(?: \d{3})*/u', $text, $matches);

        $numbers = [];
        foreach ($matches[0] as $match) {
            $numbers[] = (int) str_replace(' ', '', $match);
            $numbers[] = (int) explode(' ', $match)[0];
        }

        return array_values(array_unique($numbers));
    }

    /** @return list<string> slug-и районів, які запит згадує прямо або збірною назвою */
    private static function groundedDistricts(string $text): array
    {
        $slugs = [];

        foreach (self::DISTRICT_STEMS as $slug => $stems) {
            foreach ($stems as $stem) {
                if (preg_match('/(?<!\p{L})'.preg_quote($stem, '/').'/u', $text) === 1) {
                    $slugs[] = $slug;

                    break;
                }
            }
        }

        foreach (self::AREA_STEMS as $pattern => $areaSlugs) {
            if (preg_match('/(?<!\p{L})(?:'.$pattern.')/u', $text) === 1) {
                array_push($slugs, ...$areaSlugs);
            }
        }

        return array_values(array_unique($slugs));
    }
}
