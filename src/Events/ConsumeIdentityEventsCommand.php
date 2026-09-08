<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Events;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;
use Verdeect\IdentityIntegration\Events\DTO\IdentityEvent;

/**
 * Потребитель сообщений установки.
 *
 * Живёт под supervisor как долгоживущая команда. Подтверждения **ручные**:
 * отметка ставится до `ack`, иначе падение между чтением и обработкой
 * потеряло бы гашение сессии.
 */
final class ConsumeIdentityEventsCommand extends Command
{
    /** @var string */
    protected $signature = 'identity:consume-events
        {--once : обработать одно сообщение и выйти}
        {--timeout=0 : предел ожидания в секундах, 0 — без предела}';

    /** @var string */
    protected $description = 'Потребляет сообщения identity.events и ставит отметки гашения сессий';

    /** @var list<int> задержки переподключения в секундах */
    private const RECONNECT_DELAYS = [1, 5, 30];

    public function handle(IdentityEventDispatcher $dispatcher): int
    {
        $attempt = 0;

        while (true) {
            try {
                $this->consume($dispatcher);

                return self::SUCCESS;
            } catch (Throwable $exception) {
                if ($this->option('once')) {
                    $this->components->error($exception->getMessage());

                    return self::FAILURE;
                }

                $delay = self::RECONNECT_DELAYS[min($attempt, count(self::RECONNECT_DELAYS) - 1)];
                $attempt++;

                Log::warning('[ConsumeIdentityEventsCommand] reconnecting', [
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'reason' => $exception->getMessage(),
                ]);

                sleep($delay);
            }
        }
    }

    private function consume(IdentityEventDispatcher $dispatcher): void
    {
        $connection = $this->connect();
        $channel = $connection->channel();

        $exchange = (string) config('identity.broker.exchange', 'identity.events');
        $queue = (string) config('identity.broker.queue');

        if ($queue === '') {
            /*
             * Имя очереди принадлежит продукту и умолчания не имеет:
             * потребитель без него подписался бы неизвестно на что либо
             * завёл безымянную очередь, и потеря сообщений выяснилась бы
             * по отсутствию гашения сессий.
             */
            Log::error('[ConsumeIdentityEventsCommand] broker queue is not configured', [
                'setting' => 'identity.broker.queue',
            ]);

            throw new RuntimeException(
                'Имя очереди потребителя не задано: заполните BROKER_QUEUE.',
            );
        }

        $this->declareTopology($channel, $exchange, $queue);

        // Предвыборка ограничена: обработчик ставит отметки, а не считает,
        // и очередь из сотен сообщений в памяти пользы не даёт.
        $channel->basic_qos(0, 10, false);

        $channel->basic_consume(
            $queue,
            consumer_tag: $this->consumerTag($queue),
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: function (AMQPMessage $message) use ($dispatcher, $channel): void {
                $this->handleMessage($dispatcher, $channel, $message);
            },
        );

        $timeout = (int) $this->option('timeout');

        while ($channel->is_consuming()) {
            $channel->wait(null, false, $timeout);

            if ($this->option('once')) {
                break;
            }
        }

        $channel->close();
        $connection->close();
    }

    private function handleMessage(
        IdentityEventDispatcher $dispatcher,
        AMQPChannel $channel,
        AMQPMessage $message,
    ): void {
        $payload = json_decode($message->getBody(), true);

        if (! is_array($payload)) {
            /*
             * Нечитаемое тело в очередь не возвращается: повтор дал бы
             * тот же результат и заблокировал остальные сообщения.
             */
            Log::error('[ConsumeIdentityEventsCommand] malformed message body');
            $channel->basic_nack($message->getDeliveryTag(), false, false);

            return;
        }

        try {
            /** @var array<string, mixed> $payload */
            $event = IdentityEvent::fromArray($payload);
        } catch (Throwable $exception) {
            Log::error('[ConsumeIdentityEventsCommand] malformed message', [
                'reason' => $exception->getMessage(),
            ]);
            $channel->basic_nack($message->getDeliveryTag(), false, false);

            return;
        }

        $dispatcher->dispatch($event);

        // Подтверждение ставится и для нераспознанного типа: неизвестное
        // сообщение не должно копиться в очереди.
        $channel->basic_ack($message->getDeliveryTag());
    }

    private function declareTopology(AMQPChannel $channel, string $exchange, string $queue): void
    {
        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $channel->queue_declare($queue, false, true, false, false);

        foreach (IdentityEventDispatcher::TYPES as $type) {
            $channel->queue_bind($queue, $exchange, $type);
        }
    }

    private function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            (string) config('identity.broker.host'),
            (int) config('identity.broker.port'),
            (string) config('identity.broker.user'),
            (string) config('identity.broker.password'),
            (string) config('identity.broker.vhost'),
        );
    }

    /**
     * Метка потребителя для панели брокера.
     *
     * Имя продукта в библиотеку не зашивается: по умолчанию метка выводится
     * из имени очереди, которое продукт и так задаёт.
     */
    private function consumerTag(string $queue): string
    {
        $tag = config('identity.broker.consumer_tag');

        return is_string($tag) && $tag !== '' ? $tag : $queue.'-consumer';
    }
}
