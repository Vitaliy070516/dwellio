<?php

declare(strict_types=1);

use App\Modules\Generation\Enums\Platform;
use App\Modules\Generation\Exceptions\InvalidAdOutputException;
use App\Modules\Generation\Support\AdTextParser;

$body = 'Простора квартира з меблями у тихому дворі. До метро кілька хвилин пішки, поруч парк і магазини. '
    .'Можна з домашніми тваринами.';

it('splits the title and body on the first blank line', function () use ($body): void {
    $ad = new AdTextParser()->parse("Світла квартира на Подолі\n\n{$body}\n\nДругий абзац.", Platform::Olx);

    expect($ad->title)->toBe('Світла квартира на Подолі')
        ->and($ad->body)->toBe("{$body}\n\nДругий абзац.");
});

it('falls back to the first line break when there is no blank line', function () use ($body): void {
    $ad = new AdTextParser()->parse("Світла квартира на Подолі\n{$body}", Platform::Olx);

    expect($ad->title)->toBe('Світла квартира на Подолі')->and($ad->body)->toBe($body);
});

it('strips typical small-model artifacts: markdown, labels and quotes', function (string $raw) use ($body): void {
    $ad = new AdTextParser()->parse("{$raw}\n\nОпис: **{$body}**", Platform::Olx);

    expect($ad->title)->toBe('Світла квартира на Подолі')->and($ad->body)->toBe($body);
})->with([
    'markdown heading' => '# Світла квартира на Подолі',
    'bold label' => '**Заголовок:** Світла квартира на Подолі',
    'quotes' => '«Світла квартира на Подолі»',
    'windows newlines' => "Світла квартира на Подолі\r",
]);

it('truncates an over-long title on a word boundary instead of paying for a retry', function () use ($body): void {
    $title = str_repeat('Простора світла квартира ', 6);

    $ad = new AdTextParser()->parse("{$title}\n\n{$body}", Platform::Olx);

    expect(mb_strlen($ad->title))->toBeLessThanOrEqual(Platform::Olx->titleMaxChars())
        ->and(explode(' ', $ad->title))->each->toBeIn(['Простора', 'світла', 'квартира']);
});

it('rejects output that cannot be an ad', function (string $raw): void {
    new AdTextParser()->parse($raw, Platform::Olx);
})->with([
    'empty' => '',
    'whitespace only' => "  \n\n  ",
    'title only' => 'Світла квартира на Подолі',
    'body too short' => "Світла квартира на Подолі\n\nКоротко.",
    'title too short' => "Ок\n\n".str_repeat('Довгий опис квартири. ', 10),
])->throws(InvalidAdOutputException::class);

it('rejects a body longer than the platform allows', function (): void {
    new AdTextParser()->parse("Світла квартира\n\n".str_repeat('а', Platform::Olx->bodyMaxChars() + 1), Platform::Olx);
})->throws(InvalidAdOutputException::class);
