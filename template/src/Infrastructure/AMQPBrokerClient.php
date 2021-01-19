<?php
/**
 * Created by PhpStorm.
 * User: emiliano
 * Date: 30/12/20
 * Time: 14:37
 */

namespace {{ params.packageName }}\BrokerAPI\Infrastructure;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use {{ params.packageName }}\BrokerAPI\Messages\MessageContract;
use {{ params.packageName }}\BrokerAPI\Handlers\RPC\RPCHandlerContract;
use {{ params.packageName }}\BrokerAPI\Handlers\HandlerContract;

class AMQPBrokerClient implements BrokerClientContract
{
    /** @var AMQPStreamConnection $connection */
    private $connection;
    /** @var AMQPChannel $channel */
    private $channel;

    /**
     * AMQPBrokerClient constructor.
     * @param AMQPStreamConnection|null $connection
     */
    public function __construct(
        AMQPStreamConnection $connection = null
    ) {
        $this->setConnection($connection);
    }

    /**
     * @param $connection
     * @return BrokerClientContract
     */
    public function setConnection($connection): BrokerClientContract
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * @return AMQPStreamConnection
     */
    public function getConnection(): AMQPStreamConnection
    {
        return $this->connection;
    }

    /**
     * @return AMQPChannel
     */
    public function connect()
    {
        $this->channel = $this->connection->channel();
        return $this->channel;
    }

    /**
     * @throws Exception
     */
    public function close()
    {
        $this->channel->close();
        $this->connection->close();
    }

    /**
     * @param MessageContract $message
     * @param array $config
     * @return bool
     * @throws Exception
     */
    public function basicPublish(MessageContract $message, array $config = []): bool
    {
        try {
            /**
             * @var bool|null $mandatory
             * @var bool|null $immediate
             * @var $ticket
             * @var string $exchangeName
             * @var string $exchangeType
             * @var string $bindingKey
             */
            extract($config);
            $this->connect();
            $this->channel->exchange_declare(
                $exchangeName,
                $exchangeType,
                $passive ?? false,
                $durable ?? false,
                $autoDelete ?? true,
                $internal ?? false,
                $noWait ?? false,
                $arguments ?? [],
                $ticket ?? null
            );
            /** @var \PhpAmqpLib\Message\AMQPMessage $amqpMessage */
            $amqpMessage = $message->getPayload();
            $this->channel->basic_publish(
                $amqpMessage,
                $exchangeName ?? '',
                $bindingKey ?? '',
                $mandatory ?? false,
                $immediate ?? false,
                $ticket ?? null
            );
            $this->close();
            return true;
        } catch (\Throwable $t) {
            //log here?
            return false;
        }
    }

    /**
     * Basic consume function will default to topic through exchange with binding keys.
     * If other types of consumption are needed, refactor is needed.
     * Refactoring this functions is easy tho, please follow TDD best practices in order to do so
     *
     * @param HandlerContract $handler
     * @param array $config
     * @return bool
     * @throws ErrorException
     */
    public function basicConsume(
        HandlerContract $handler,
        array $config = []
    ): bool
    {
        try {
            /**
             * @var string|null $consumerTag
             * @var bool|null $noLocal
             * @var bool|null $noAck
             * @var bool|null $exclusive
             * @var bool|null $noWait
             * @var $callback
             * @var $ticket
             * @var array|null $arguments
             * @var string $exchangeName
             * @var string $exchangeType
             * @var string $bindingKey
             * @var bool $exchangeDurable
             * @var bool $queueDurable
             * @var bool $autoDelete
             */
            extract($config);
            $this->connect();
            $this->channel->exchange_declare(
                $exchangeName,
                $exchangeType,
                false,
                $exchangeDurable ?? false,
                $autoDelete ?? true,
                false,
                $noWait ?? false,
                $arguments ?? [],
                $ticket ?? null
            );
            list($queueName) = $this->channel->queue_declare(
                "",
                false,
                $queueDurable ?? false,
                $autoDelete ?? true,
                false
            );
            $this->channel->queue_bind($queueName, $exchangeName, $bindingKey);
            $this->channel->basic_consume(
                $queueName,
                $consumerTag ?? '',
                $noLocal ?? false,
                $noAck ?? false,
                $exclusive ?? false,
                $noWait ?? false,
                [
                    $handler,
                    'handle'
                ],
                $ticket ?? null,
                $arguments ?? []
            );

            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }

            $this->close();

            return true;
        } catch (\Throwable $t) {
            //log here
            return false;
        }
    }

    /**
     * @param MessageContract $message
     * @param RPCHandlerContract $handler
     * @param array $config
     * @return AMQPMessage
     * @throws ErrorException
     */
    public function rpcPublish(
        MessageContract $message,
        RPCHandlerContract $handler,
        array $config = []
    ): AMQPMessage
    {
        /**
         * @var $bindingKey
         */
        extract($config);
        $this->connect();

        list($queue) = $this->channel->queue_declare(
            "",
            false,
            false,
            true,
            false
        );
        $this->channel->basic_consume(
            $queue,
            '',
            false,
            true,
            false,
            false,
            [
                $handler,
                'handle',
            ]
        );

        /** @var AMQPMessage $amqpMessage */
        $amqpMessage = $message->getPayload();
        $amqpMessage->set('reply_to', $queue);
        if(!$amqpMessage->has('correlation_id')) {
            $correlationId = Uuid::uuid4()->toString();
            $amqpMessage->set('correlation_id', $correlationId);
            $handler->setCorrelationId($correlationId);
        }

        $this->channel->basic_publish($amqpMessage, '', $bindingKey);

        while (!$amqpRPCMessage = $handler->getMessage()) {
            $this->channel->wait();
        }

        $this->close();

        return $amqpRPCMessage;
    }

    /**
     * @param RPCHandlerContract $handler
     * @param array $config
     * @return bool
     */
    public function rpcConsume(
        RPCHandlerContract $handler,
        array $config = []
    ): bool
    {
        try {
            /**
             * @var $queueName
             */
            extract($config);
            $this->connect();
            $this->channel->queue_declare(
                $queueName,
                false,
                false,
                false,
                false
            );

            $this->channel->basic_qos(null, 1, null);
            $this->channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                [
                    $handler,
                    'handle'
                ]
            );

            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }

            $this->close();

            return true;
        }catch(\Throwable $t) {
            //log the exception
            return false;
        }
    }
}
