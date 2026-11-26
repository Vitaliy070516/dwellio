<?php

declare(strict_types=1);

namespace App\Modules\Generation\Support;

use App\Modules\Generation\DTO\GeneratedAd;
use App\Modules\Generation\Enums\Platform;
use App\Modules\Generation\Exceptions\InvalidAdOutputException;

/**
 * Розбирає відповідь моделі формату «заголовок, порожній рядок, текст».
 * Відповіді LLM не довіряємо: прибираємо типові артефакти (Markdown, «Заголовок:», лапки)
 * і перевіряємо ліміти майданчика. Задовгий заголовок обрізаємо по слову (дешевше за повтор),
 * а відсутній чи надто короткий / довгий текст відхиляємо.
 */
final readonly class AdTextParser
{
    public function parse(string $raw, Platform $platform): GeneratedAd
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $raw));

        if ($text === '') {
            throw new InvalidAdOutputException('Model returned an empty response.');
        }

        // Заголовок — до першого порожнього рядка; якщо його немає, то до першого переносу.
        $parts = preg_split('/\n[ \t]*\n/u', $text, 2);
        if ($parts === false || count($parts) < 2) {
            $parts = explode("\n", $text, 2);
        }

        if (count($parts) < 2) {
            throw new InvalidAdOutputException('Model response has no body after the title.');
        }

        $title = $this->cleanTitle($parts[0]);
        $body = $this->cleanBody($parts[1]);

        if (mb_strlen($title) < 5) {
            throw new InvalidAdOutputException('Title is missing or too short.');
        }

        $bodyLength = mb_strlen($body);
        if ($bodyLength < $platform->bodyMinChars() || $bodyLength > $platform->bodyMaxChars()) {
            throw new InvalidAdOutputException(sprintf(
                'Body length %d is outside %d..%d.', $bodyLength, $platform->bodyMinChars(), $platform->bodyMaxChars(),
            ));
        }

        return new GeneratedAd($this->truncateByWord($title, $platform->titleMaxChars()), $body);
    }

    private function cleanTitle(string $line): string
    {
        $line = trim(str_replace("\n", ' ', $line));
        // «**Заголовок:** ...», «# ...», «"..."» — типові артефакти малих моделей.
        $line = (string) preg_replace('/^(?:#+\s*|\*\*|__)+/u', '', $line);
        $line = (string) preg_replace('/^(?:заголовок|title)\s*[:\-–—]\s*/iu', '', $line);
        $line = str_replace(['**', '__'], '', $line);

        return trim($line, " \t\"'«»“”");
    }

    private function cleanBody(string $text): string
    {
        $text = (string) preg_replace('/^(?:опис|текст|description)\s*[:\-–—]\s*/iu', '', trim($text));
        $text = str_replace(['**', '__'], '', $text);
        // Не більше одного порожнього рядка між абзацами.
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    private function truncateByWord(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false && $lastSpace > $max / 2 ? mb_substr($cut, 0, $lastSpace) : $cut, ' ,.;:-–—');
    }
}
