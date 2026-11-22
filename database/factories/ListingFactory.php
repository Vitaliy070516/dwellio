<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Listings\Enums\District;
use App\Modules\Listings\Enums\ListingStatus;
use App\Modules\Listings\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Реалістичні київські квартири. Тексти складаються з фрагментів із різними
 * «семантичними» ознаками (тиша, тварини, метро, ремонт), щоб на Дні 5
 * гібридний пошук мав що розрізняти.
 *
 * @extends Factory<Listing>
 */
final class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * Станції метро (назва, lat, lng) і цінова ступінь району (множник до базової ціни за м²).
     * Координати наближені: вистачає для демо, не для навігації.
     *
     * @var array<string, array{tier: float, stations: list<array{string, float, float}>}>
     */
    private const array GEO = [
        'pecherskyi' => ['tier' => 1.5, 'stations' => [['Печерська', 50.4448, 30.5389], ['Арсенальна', 50.4443, 30.5453], ['Дружби народів', 50.4054, 30.5430]]],
        'shevchenkivskyi' => ['tier' => 1.35, 'stations' => [["Лук'янівська", 50.4623, 30.4817], ['Золоті ворота', 50.4484, 30.5139], ['Нивки', 50.4587, 30.4040]]],
        'podilskyi' => ['tier' => 1.2, 'stations' => [['Контрактова площа', 50.4658, 30.5154], ['Поштова площа', 50.4586, 30.5252], ['Почайна', 50.4866, 30.4988]]],
        'solomianskyi' => ['tier' => 1.1, 'stations' => [['Вокзальна', 50.4417, 30.4881], ['Шулявська', 50.4546, 30.4452], ['Либідська', 50.4261, 30.5183]]],
        'holosiivskyi' => ['tier' => 1.0, 'stations' => [['Голосіївська', 50.3787, 30.5083], ['Деміївська', 50.4062, 30.5164], ['Васильківська', 50.3932, 30.4876], ['Теремки', 50.3573, 30.4549]]],
        'obolonskyi' => ['tier' => 1.0, 'stations' => [['Оболонь', 50.5014, 30.4983], ['Мінська', 50.5121, 30.4983], ['Героїв Дніпра', 50.5226, 30.4983]]],
        'dniprovskyi' => ['tier' => 0.95, 'stations' => [['Лівобережна', 50.4519, 30.5987], ['Гідропарк', 50.4457, 30.5762], ['Чернігівська', 50.4590, 30.6305]]],
        'sviatoshynskyi' => ['tier' => 0.9, 'stations' => [['Академмістечко', 50.4647, 30.3547], ['Житомирська', 50.4562, 30.3664], ['Святошин', 50.4579, 30.3908]]],
        'darnytskyi' => ['tier' => 0.85, 'stations' => [['Харківська', 50.4012, 30.6521], ['Бориспільська', 50.3957, 30.6674], ['Вирлиця', 50.4023, 30.6786]]],
        'desnianskyi' => ['tier' => 0.8, 'stations' => [['Лісова', 50.4647, 30.6455]]],
    ];

    private const array STREETS = [
        'вул. Хрещатик', 'вул. Саксаганського', 'вул. Антоновича', 'вул. Богдана Хмельницького',
        'вул. Лесі Українки', 'просп. Перемоги', 'просп. Лобановського', 'вул. Драгоманова',
        'вул. Мілютенка', 'просп. Володимира Івасюка', 'вул. Йорданська', 'вул. Сковороди',
        'вул. Предславинська', 'вул. Героїв Севастополя', 'вул. Здолбунівська', 'вул. Каштанова',
    ];

    private const array QUIET = [
        'Тихий двір, вікна виходять у зелений двір.',
        'Дуже тихий будинок, сусіди спокійні, шуму від дороги немає.',
        'Спокійна локація, ідеально для тих, хто працює вдома.',
    ];

    private const array LIVELY = [
        'Жвава вулиця, поруч кафе, супермаркет і аптека.',
        'Розвинена інфраструктура: магазини, ресторани та спортзал у п\'яти хвилинах.',
    ];

    private const array CONDITION = [
        'Свіжий євроремонт, усе нове.',
        'Косметичний ремонт, охайна квартира.',
        'Житловий стан, без зайвого декору.',
        'Дизайнерський ремонт, продумане зонування.',
    ];

    private const array PETS_OK = [
        'Можна з котом або невеликою собакою.',
        'Власники лояльні до домашніх улюбленців.',
    ];

    private const array PETS_NO = [
        'Без тварин, будь ласка.',
        'Тварин не розглядаємо.',
    ];

    public function definition(): array
    {
        $district = $this->pick(District::cases());
        $geo = self::GEO[$district->value];

        $rooms = $this->pick([1, 1, 1, 2, 2, 2, 3, 3, 4]);
        $area = round(26 + ($rooms - 1) * 19 + fake()->numberBetween(0, 120) / 10, 1);

        $hasMetro = fake()->boolean(85);
        /** @var array{string, float, float} $station */
        $station = $this->pick($geo['stations']);
        $metroDistance = $hasMetro ? fake()->numberBetween(15, 250) * 10 : null;

        $floorsTotal = $this->pick([5, 9, 9, 16, 16, 24]);
        $petsAllowed = fake()->boolean(35);
        $furnished = fake()->boolean(70);

        // Базово ~370 грн/м² на місяць із множником району, ±15% шуму, округлення до 500.
        $price = (int) (round($area * 370 * $geo['tier'] * fake()->numberBetween(85, 115) / 100 / 500) * 500);

        $sentences = [
            $this->pick(fake()->boolean(60) ? self::QUIET : self::LIVELY),
            $this->pick(self::CONDITION),
            $furnished ? 'Є меблі та уся необхідна техніка.' : 'Квартира без меблів, можна обставити під себе.',
            $this->pick($petsAllowed ? self::PETS_OK : self::PETS_NO),
        ];
        if ($hasMetro && $metroDistance !== null) {
            array_splice($sentences, 1, 0, sprintf(
                'До метро %s приблизно %d хв пішки.',
                $station[0],
                max(1, (int) round($metroDistance / 80)),
            ));
        }

        $titleMetro = $hasMetro ? ', м. '.$station[0] : '';

        return [
            'title' => sprintf('%d-кімнатна квартира, %s р-н%s', $rooms, $district->label(), $titleMetro),
            'description' => implode(' ', $sentences),
            'price_uah' => max(5000, $price),
            'rooms' => $rooms,
            'area_m2' => $area,
            'floor' => fake()->numberBetween(1, $floorsTotal),
            'floors_total' => $floorsTotal,
            'district' => $district,
            'address' => $this->pick(self::STREETS).', '.fake()->numberBetween(1, 120),
            'metro_station' => $hasMetro ? $station[0] : null,
            'metro_distance_m' => $metroDistance,
            'lat' => round($station[1] + fake()->numberBetween(-800, 800) / 100000, 6),
            'lng' => round($station[2] + fake()->numberBetween(-800, 800) / 100000, 6),
            'pets_allowed' => $petsAllowed,
            'furnished' => $furnished,
            // ~90% активних, решта чернетки й архів — щоб фільтри за статусом було що перевіряти.
            'status' => $this->pick([
                ...array_fill(0, 18, ListingStatus::Active),
                ListingStatus::Draft,
                ListingStatus::Archived,
            ]),
        ];
    }

    public function active(): self
    {
        return $this->state(['status' => ListingStatus::Active]);
    }

    public function draft(): self
    {
        return $this->state(['status' => ListingStatus::Draft]);
    }

    public function archived(): self
    {
        return $this->state(['status' => ListingStatus::Archived]);
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[fake()->numberBetween(0, count($items) - 1)];
    }
}
