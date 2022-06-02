<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace Amp\Websocket\Client\Test;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\EmptyHandshakeHandler;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\WebsocketClient;
use Psr\Log\NullLogger;
use function Amp\async;
use function Amp\delay;
use function Amp\Websocket\Client\connect;

class WebsocketConnectionTest extends AsyncTestCase
{
    /**
     * This method creates a new server that listens on a randomly assigned port and returns the used port.
     *
     * @return array{HttpServer, SocketAddress} Returns the HttpServer instance and the used port number.
     * @throws SocketException
     */
    protected function createServer(ClientHandler $clientHandler): array
    {
        $logger = new NullLogger;

        $httpServer = new SocketHttpServer($logger);
        $httpServer->expose(new InternetAddress('127.0.0.1', 0));
        $httpServer->start(
            new Websocket($logger, new EmptyHandshakeHandler(), $clientHandler),
            new DefaultErrorHandler(),
        );

        $server = $httpServer->getServers()[0] ?? self::fail('HTTP server failed to create server sockets');

        return [$httpServer, $server->getAddress()];
    }

    public function testSimpleBinaryEcho(): void
    {
        [$server, $address] = $this->createServer(new class implements ClientHandler {
            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                while ($message = $client->receive()) {
                    $client->sendBinary($message->buffer());
                }
            }
        });

        try {
            $client = connect('ws://' . $address->toString());
            $client->sendBinary('Hey!');

            $message = $client->receive();

            $this->assertTrue($message->isBinary());
            $this->assertSame('Hey!', $message->buffer());

            $future = async($client->receive(...), new TimeoutCancellation(1));
            $client->close();

            $this->assertNull($future->await());
        } finally {
            $server->stop();
        }
    }

    public function testSimpleTextEcho(): void
    {
        [$server, $address] = $this->createServer(new class implements ClientHandler {
            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                while ($message = $client->receive()) {
                    $client->send($message->buffer());
                }
            }
        });

        try {
            $client = connect('ws://' . $address->toString());
            $client->send('Hey!');

            $message = $client->receive();

            $this->assertFalse($message->isBinary());
            $this->assertSame('Hey!', $message->buffer());

            $future = async($client->receive(...), new TimeoutCancellation(1));
            $client->close();

            $this->assertNull($future->await());
        } finally {
            $server->stop();
        }
    }

    public function testUnconsumedMessage(): void
    {
        [$server, $address] = $this->createServer(new class implements ClientHandler {
            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                $client->send(\str_repeat('.', 1024 * 1024));
                $client->send('Message');
                $client->close();
            }
        });

        try {
            $client = connect('ws://' . $address->toString());

            $message = $client->receive(new TimeoutCancellation(1));

            // Do not consume the bytes from the first message.
            unset($message);

            delay(0);

            $message = $client->receive(new TimeoutCancellation(1));
            $this->assertFalse($message->isBinary());
            $this->assertSame('Message', $message->buffer());

            $future = async($client->receive(...), new TimeoutCancellation(1));
            $client->close();

            $this->assertNull($future->await());
        } finally {
            $server->stop();
        }
    }

    public function testVeryLongMessage(): void
    {
        [$server, $address] = $this->createServer(new class implements ClientHandler {
            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                $payload = \str_repeat('.', 1024 * 1024 * 10); // 10 MiB
                $client->sendBinary($payload);
            }
        });

        $connector = new Client\Rfc6455Connector(
            connectionFactory: new Client\Rfc6455ConnectionFactory(messageSizeLimit: 1024 * 1024 * 10),
        );

        try {
            $client = $connector->connect(new Client\WebsocketHandshake('ws://' . $address->toString()));

            $message = $client->receive(new TimeoutCancellation(1));
            $this->assertSame(\str_repeat('.', 1024 * 1024 * 10), $message->buffer());
        } finally {
            $server->stop();
        }
    }

    public function testTooLongMessage(): void
    {
        [$server, $address] = $this->createServer(new class() implements ClientHandler {
            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                $payload = \str_repeat('.', 1024 * 1024 * 10 + 1); // 10 MiB + 1 byte
                $client->sendBinary($payload);
            }
        });

        $connector = new Client\Rfc6455Connector(
            connectionFactory: new Client\Rfc6455ConnectionFactory(messageSizeLimit: 1024 * 1024 * 10),
        );

        try {
            $client = $connector->connect(new Client\WebsocketHandshake('ws://' . $address->toString()));

            $message = $client->receive(new TimeoutCancellation(1));
            $message->buffer();

            self::fail('Buffering the message should have thrown a ClosedException due to exceeding the message size limit');
        } catch (ClosedException $exception) {
            $this->assertSame('Received payload exceeds maximum allowable size', $exception->getReason());
        } finally {
            $server->stop();
        }
    }
}
