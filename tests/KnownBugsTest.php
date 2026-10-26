<?php

namespace PhpAgi\Tests;

use AGI;
use PhpAgi\Tests\Support\AmiTestCase;
use PhpAgi\Tests\Support\ChildProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bugs found while writing the characterization suite. Each test asserts the INTENDED
 * behaviour, so they fail today. They are excluded from the default run (see phpunit.xml)
 * and can be run with: composer test:known-bugs
 *
 * When fixing one, move its test into the regular suite.
 */
#[Group('known-bug')]
class KnownBugsTest extends AmiTestCase
{
    /**
     * fgets() returns false at EOF, which never equals "\n", so the header loop in
     * AGI::__construct spins forever when STDIN closes before the blank line.
     */
    public function testConstructorStopsWhenStdinClosesBeforeEndOfHeader(): void
    {
        $res = ChildProcess::run(
            __DIR__ . '/fixtures/construct-agi.php',
            [sys_get_temp_dir()],
            "agi_request: test.php\n", // no terminating blank line
            3.0
        );

        $this->assertFalse($res['timedOut'], 'AGI constructor loops forever on EOF');
    }

    /**
     * read_one_msg() array_pop()s the last line of every Success/Follows response to look
     * for '--END COMMAND--' and never puts it back, so the last header is lost. GetVar's
     * 'Value' is always the last header, so GetVar never returns the value.
     */
    public function testGetVarReturnsValue(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Variable: FOO', 'Value: bar');

        $this->assertSame('bar', $this->manager->GetVar('SIP/1', 'FOO')['Value'] ?? null);
    }

    /**
     * ChangeMonitor() sends the action name misspelled as 'ChangeMontior'.
     */
    public function testChangeMonitorSendsCorrectActionName(): void
    {
        $this->reply('Response: Success', 'ActionID: A1', 'Message: Changed monitor filename');

        $this->manager->ChangeMonitor('SIP/1', 'newfile');

        $this->assertStringStartsWith("Action: ChangeMonitor\r\n", $this->sent());
    }

    /**
     * process_event() calls function_exists($handler) first, which throws a TypeError on
     * PHP 8 for array callbacks, so [$object, 'method'] handlers never run.
     */
    public function testArrayCallbackEventHandlerIsCalled(): void
    {
        $handler = new class {
            public array $seen = [];

            public function onEvent($event)
            {
                $this->seen[] = $event;
                return true;
            }
        };
        $this->manager->add_event_handler('Hangup', [$handler, 'onEvent']);

        $this->manager->process_event(['Event' => 'Hangup']);

        $this->assertSame(['hangup'], $handler->seen);
    }

    /**
     * say_punctuation() is missing a 'break' after the space case, so every space is
     * spoken as "SPACE COMMA".
     */
    public function testSayPunctuationSpeaksSpaceOnce(): void
    {
        $agi = new class extends AGI {
            public array $spoken = [];

            public function __construct()
            {
            }

            function text2wav($text, $escape_digits = '', $frequency = 8000)
            {
                $this->spoken[] = $text;
                return [];
            }
        };

        $agi->say_punctuation('a b');

        $this->assertSame(['a SPACE b '], $agi->spoken);
    }
}
