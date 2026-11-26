<?php

declare(strict_types=1);

namespace App\Web\Support;

use App\Shared\Demo\DemoMode;

/**
 * Параметри підключення браузера до Reverb, які сторінка отримує в runtime (meta `reverb-config`).
 *
 * `host: null` = той самий origin, що й сторінка: JS бере `location` (схему, хост, порт).
 * Так працює тунель із випадковим доменом *.trycloudflare.com: Caddy проксіює `/app/*` у Reverb.
 * Секрет застосунку сюди не потрапляє — лише публічний `key` (його й так видно в URL WebSocket).
 */
final readonly class ReverbClientConfig
{
    public function __construct(
        public string $key,
        public ?string $host,
        public ?int $port,
        public ?string $scheme,
    ) {}

    public static function fromConfig(): self
    {
        $key = config('broadcasting.connections.reverb.key');
        $host = config('dwellio.reverb_client.host');
        $port = config('dwellio.reverb_client.port');
        $scheme = config('dwellio.reverb_client.scheme');

        // Тунель і будь-який реверс-проксі = один хост: явні host/port з .env там лише зашкодять.
        if (DemoMode::enabled() || ! is_string($host) || $host === '') {
            return new self(is_string($key) ? $key : '', null, null, null);
        }

        return new self(
            is_string($key) ? $key : '',
            $host,
            is_numeric($port) ? (int) $port : null,
            is_string($scheme) && $scheme !== '' ? $scheme : null,
        );
    }

    /** @return array{key: string, host: string|null, port: int|null, scheme: string|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'host' => $this->host, 'port' => $this->port, 'scheme' => $this->scheme];
    }
}
