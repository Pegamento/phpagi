<?php

namespace PhpAgi\Tests\Support;

use AGI_AsteriskManager;
use PHPUnit\Framework\TestCase;

/**
 * Base class for Asterisk Manager tests.
 *
 * The manager talks to a connected socket pair instead of a real Asterisk: tests queue
 * the server's replies on the far end before calling, then read back what was sent.
 * ActionIDs are fixed to 'A1' so replies can be scripted.
 */
abstract class AmiTestCase extends TestCase
{
    protected AGI_AsteriskManager $manager;

    /** @var resource the "Asterisk" end of the socket pair */
    protected $server;

    protected function setUp(): void
    {
        $domain = PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX;
        [$client, $this->server] = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->manager = new class (EMPTY_CONFIG, ['write_log' => false]) extends AGI_AsteriskManager {
            function ActionID()
            {
                return 'A1';
            }
        };
        $this->manager->socket = $client;
    }

    protected function tearDown(): void
    {
        fclose($this->server);
        fclose($this->manager->socket);
    }

    /**
     * Queue a manager message (headers as 'Key: Value' lines) for the client to read.
     */
    protected function reply(string ...$lines): void
    {
        fwrite($this->server, implode("\r\n", $lines) . "\r\n\r\n");
    }

    /**
     * @return string everything the client has sent so far
     */
    protected function sent(): string
    {
        stream_set_blocking($this->server, false);
        $data = stream_get_contents($this->server);
        stream_set_blocking($this->server, true);

        return $data;
    }
}
