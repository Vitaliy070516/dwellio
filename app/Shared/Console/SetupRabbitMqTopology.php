<?php

declare(strict_types=1);

namespace App\Shared\Console;

use Illuminate\Console\Command;

/**
 * Створює "хвіст" топології RabbitMQ: failed-exchange, DLQ і прив'язку.
 *
 * Основні черги НЕ створюємо вручну: драйвер оголошує їх сам з аргументами
 * x-dead-letter-*. Якщо оголосити чергу заздалегідь без цих аргументів,
 * наступне оголошення впаде з PRECONDITION_FAILED. Команда ідемпотентна.
 */
final class SetupRabbitMqTopology extends Command
{
    /** Черги, для яких потрібна DLQ. Нова черга (напр. generation) = новий рядок тут. */
    private const array QUEUES = ['embeddings'];

    protected $signature = 'dwellio:rabbitmq-setup {connection=rabbitmq : Назва queue-підключення}';

    protected $description = 'Declare the dead-letter exchange, DLQs and bindings in RabbitMQ (idempotent)';

    public function handle(): int
    {
        $connection = (string) $this->argument('connection');
        $exchange = config()->string("queue.connections.{$connection}.options.queue.failed_exchange", '');

        if ($exchange === '') {
            $this->error("queue.connections.{$connection}.options.queue.failed_exchange is not configured.");

            return self::FAILURE;
        }

        $this->call('rabbitmq:exchange-declare', ['name' => $exchange, 'connection' => $connection, '--type' => 'direct']);

        foreach (self::QUEUES as $queue) {
            $dlq = "{$queue}.failed";

            $this->call('rabbitmq:queue-declare', ['name' => $dlq, 'connection' => $connection]);
            $this->call('rabbitmq:queue-bind', [
                'queue' => $dlq,
                'exchange' => $exchange,
                'connection' => $connection,
                '--routing-key' => $dlq,
            ]);
        }

        return self::SUCCESS;
    }
}
