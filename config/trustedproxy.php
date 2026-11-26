<?php

declare(strict_types=1);

/*
| Кому вірити в X-Forwarded-For / -Proto / -Host (middleware TrustProxies фреймворку).
|
| За замовчуванням — нікому: заголовки ігноруються, `$request->ip()` = адреса TCP-з'єднання.
| На демо (День 12) — лише статичній IP Caddy в мережі `demo` (див. compose.yaml), бо:
| - `*` дозволив би будь-кому підробити X-Forwarded-For і обійти ліміти «на IP»;
| - вся підмережа Docker включає шлюз, тобто запити з хоста напряму на порт 80.
| Caddy сам перезаписує X-Forwarded-For значенням CF-Connecting-IP (його ставить Cloudflare, клієнт не підробить).
*/
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
