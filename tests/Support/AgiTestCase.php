<?php

namespace PhpAgi\Tests\Support;

use AGI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Base class for AGI tests.
 *
 * The real AGI constructor reads the request header from STDIN, so tests build the
 * object without it and wire in-memory streams instead: `in` holds the replies Asterisk
 * would send, `out` collects the commands the library writes.
 */
abstract class AgiTestCase extends TestCase
{
    /**
     * @param string[] $replies lines Asterisk answers with, one per command (newline is added)
     * @param string|null $class AGI subclass to instantiate
     */
    protected function makeAgi(array $replies = [], ?string $class = null): AGI
    {
        /** @var AGI $agi */
        $agi = (new ReflectionClass($class ?? AGI::class))->newInstanceWithoutConstructor();
        $agi->config = ['phpagi' => ['debug' => false, 'tempdir' => sys_get_temp_dir()]];
        $agi->request = [];

        $agi->in = fopen('php://memory', 'w+');
        fwrite($agi->in, implode('', array_map(fn ($r) => $r . "\n", $replies)));
        rewind($agi->in);

        $agi->out = fopen('php://memory', 'w+');

        return $agi;
    }

    /**
     * @return string[] commands written to Asterisk, without trailing newlines
     */
    protected function sentCommands(AGI $agi): array
    {
        rewind($agi->out);
        $lines = explode("\n", stream_get_contents($agi->out));
        array_pop($lines); // trailing newline after the last command

        return $lines;
    }

    protected function assertSent(AGI $agi, string ...$expected): void
    {
        $this->assertSame($expected, $this->sentCommands($agi));
    }
}
