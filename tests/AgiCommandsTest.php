<?php

namespace PhpAgi\Tests;

use AGI;
use PhpAgi\Tests\Support\AgiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the exact AGI command line each public method sends to Asterisk.
 */
class AgiCommandsTest extends AgiTestCase
{
    public static function commandProvider(): array
    {
        return [
            'answer' => [fn (AGI $a) => $a->answer(), 'ANSWER'],
            'channel_status default' => [fn (AGI $a) => $a->channel_status(), 'CHANNEL STATUS'],
            'channel_status named' => [fn (AGI $a) => $a->channel_status('SIP/1000'), 'CHANNEL STATUS SIP/1000'],
            'database_del' => [fn (AGI $a) => $a->database_del('fam', 'key'), 'DATABASE DEL "fam" "key"'],
            'database_deltree family' => [fn (AGI $a) => $a->database_deltree('fam'), 'DATABASE DELTREE "fam"'],
            'database_deltree keytree' => [fn (AGI $a) => $a->database_deltree('fam', 'tree'), 'DATABASE DELTREE "fam" "tree"'],
            'database_get' => [fn (AGI $a) => $a->database_get('fam', 'key'), 'DATABASE GET "fam" "key"'],
            'database_put escapes' => [fn (AGI $a) => $a->database_put('fam', 'key', "it's \"x\"\nnext"), 'DATABASE PUT "fam" "key" "it\\\'s \\"x\\"\\nnext"'],
            'set_global_var numeric' => [fn (AGI $a) => $a->set_global_var('FOO', 42), 'Set(FOO=42,g);'],
            'set_global_var string' => [fn (AGI $a) => $a->set_global_var('FOO', 'bar'), 'Set(FOO="bar",g);'],
            'set_var numeric' => [fn (AGI $a) => $a->set_var('FOO', '7'), 'Set(FOO=7);'],
            'set_var string' => [fn (AGI $a) => $a->set_var('FOO', 'bar'), 'Set(FOO="bar");'],
            'exec string options' => [fn (AGI $a) => $a->exec('Wait', '5'), 'EXEC Wait 5'],
            'exec array options' => [fn (AGI $a) => $a->exec('Dial', ['SIP/1', '30', 'tT']), 'EXEC Dial SIP/1|30|tT'],
            'get_data minimal' => [fn (AGI $a) => $a->get_data('beep'), 'GET DATA beep'],
            'get_data full' => [fn (AGI $a) => $a->get_data('beep', 3000, 4), 'GET DATA beep 3000 4'],
            'get_variable' => [fn (AGI $a) => $a->get_variable('CALLERID(num)'), 'GET VARIABLE CALLERID(num)'],
            'get_fullvariable' => [fn (AGI $a) => $a->get_fullvariable('${EXTEN}'), 'GET FULL VARIABLE ${EXTEN}'],
            'get_fullvariable channel' => [fn (AGI $a) => $a->get_fullvariable('${EXTEN}', 'SIP/1'), 'GET FULL VARIABLE ${EXTEN} SIP/1'],
            'hangup' => [fn (AGI $a) => $a->hangup(), 'HANGUP'],
            'hangup channel' => [fn (AGI $a) => $a->hangup('SIP/1'), 'HANGUP SIP/1'],
            'caused_hangup without cause' => [fn (AGI $a) => $a->caused_hangup(), 'EXEC Hangup'],
            'caused_hangup with cause' => [fn (AGI $a) => $a->caused_hangup(17), 'EXEC Hangup 17'],
            // empty(0) is true, so cause 0 (AST_CAUSE_NOTDEFINED) is sent without a code.
            'caused_hangup zero cause is dropped' => [fn (AGI $a) => $a->caused_hangup(0), 'EXEC Hangup'],
            'noop' => [fn (AGI $a) => $a->noop('hello'), 'NOOP "hello"'],
            'receive_char' => [fn (AGI $a) => $a->receive_char(500), 'RECEIVE CHAR 500'],
            'record_file minimal' => [fn (AGI $a) => $a->record_file('/tmp/x', 'wav'), 'RECORD FILE /tmp/x wav "" -1'],
            'record_file full' => [fn (AGI $a) => $a->record_file('/tmp/x', 'wav', '#', 5000, 0, true, 3), 'RECORD FILE /tmp/x wav "#" 5000 0 BEEP s=3'],
            'say_alpha' => [fn (AGI $a) => $a->say_alpha('abc', '#'), 'SAY ALPHA abc "#"'],
            'say_digits' => [fn (AGI $a) => $a->say_digits('123'), 'SAY DIGITS 123 ""'],
            'say_number' => [fn (AGI $a) => $a->say_number(42, '*#'), 'SAY NUMBER 42 "*#"'],
            'say_phonetic' => [fn (AGI $a) => $a->say_phonetic('abc'), 'SAY PHONETIC abc ""'],
            'say_time' => [fn (AGI $a) => $a->say_time(1700000000), 'SAY TIME 1700000000 ""'],
            'send_image' => [fn (AGI $a) => $a->send_image('logo'), 'SEND IMAGE logo'],
            'send_text' => [fn (AGI $a) => $a->send_text('hi there'), 'SEND TEXT "hi there"'],
            'set_autohangup' => [fn (AGI $a) => $a->set_autohangup(30), 'SET AUTOHANGUP 30'],
            'set_callerid' => [fn (AGI $a) => $a->set_callerid('"Bob" <100>'), 'SET CALLERID "Bob" <100>'],
            'set_context' => [fn (AGI $a) => $a->set_context('default'), 'SET CONTEXT default'],
            'set_extension' => [fn (AGI $a) => $a->set_extension('100'), 'SET EXTENSION 100'],
            'set_music on' => [fn (AGI $a) => $a->set_music(true, 'jazz'), 'SET MUSIC ON jazz'],
            'set_music off' => [fn (AGI $a) => $a->set_music(false), 'SET MUSIC OFF'],
            'set_priority' => [fn (AGI $a) => $a->set_priority(3), 'SET PRIORITY 3'],
            'set_variable escapes' => [fn (AGI $a) => $a->set_variable('FOO', "a\"b\nc"), 'SET VARIABLE FOO "a\\"b\\nc"'],
            'stream_file' => [fn (AGI $a) => $a->stream_file('demo-congrats', '#', 100), 'STREAM FILE demo-congrats "#" 100'],
            'tdd_mode' => [fn (AGI $a) => $a->tdd_mode('on'), 'TDD MODE on'],
            'wait_for_digit' => [fn (AGI $a) => $a->wait_for_digit(2000), 'WAIT FOR DIGIT 2000'],
            'exec_absolutetimeout' => [fn (AGI $a) => $a->exec_absolutetimeout(60), 'EXEC AbsoluteTimeout 60'],
            'exec_agi' => [fn (AGI $a) => $a->exec_agi('script.php', 'arg'), 'EXEC AGI script.php arg'],
            'exec_setlanguage' => [fn (AGI $a) => $a->exec_setlanguage('nl'), 'EXEC Set CHANNEL(language)=nl'],
            'exec_enumlookup' => [fn (AGI $a) => $a->exec_enumlookup('+31201234567'), 'EXEC EnumLookup +31201234567'],
            'exec_dial minimal' => [fn (AGI $a) => $a->exec_dial('SIP', '1000'), 'EXEC Dial SIP/1000'],
            'exec_dial full' => [fn (AGI $a) => $a->exec_dial('SIP', '1000', 30, 'tT', 'http://x'), 'EXEC Dial SIP/1000,30,tT,http://x'],
            'exec_goto priority only' => [fn (AGI $a) => $a->exec_goto('1'), 'EXEC Goto 1'],
            'exec_goto full' => [fn (AGI $a) => $a->exec_goto('ctx', 's', 1), 'EXEC Goto ctx,s,1'],
        ];
    }

    #[DataProvider('commandProvider')]
    public function testSendsExpectedCommand(callable $call, string $expected): void
    {
        $agi = $this->makeAgi(['200 result=0']);

        $call($agi);

        $this->assertSent($agi, $expected);
    }

    public function testCommandsReturnParsedReply(): void
    {
        $agi = $this->makeAgi(['200 result=1']);

        $this->assertSame(['code' => '200', 'result' => '1', 'data' => ''], $agi->caused_hangup(16));
    }

    public function testSetContextSendsContextExtensionAndPriority(): void
    {
        $agi = $this->makeAgi(['200 result=0', '200 result=0', '200 result=0']);

        $agi->setContext('sales', '100', 2);

        $this->assertSent($agi, 'SET CONTEXT sales', 'SET EXTENSION 100', 'SET PRIORITY 2');
    }

    public function testVerboseSendsOneCommandPerLine(): void
    {
        $agi = $this->makeAgi(['200 result=1', '200 result=1']);

        $agi->verbose("first\r\nsecond", 3);

        $this->assertSent($agi, 'VERBOSE "first" 3', 'VERBOSE "second" 3');
    }

    public function testGetVariableCanReturnValueOnly(): void
    {
        $agi = $this->makeAgi(['200 result=1 (1000)']);

        $this->assertSame('1000', $agi->get_variable('EXTEN', true));
    }

    public function testGetFullVariableCanReturnValueOnly(): void
    {
        $agi = $this->makeAgi(['200 result=1 (sales)']);

        $this->assertSame('sales', $agi->get_fullvariable('${CONTEXT}', false, true));
    }

    public static function channelStatusProvider(): array
    {
        return [
            [-1, 'There is no channel that matches SIP/9'],
            [0, 'Channel is down and available'],
            [6, 'Line is up'],
            [7, 'Line is busy'],
            [9, 'Channel has detected an incoming call and is waiting for ring'],
            [42, 'Unknown (42)'],
        ];
    }

    #[DataProvider('channelStatusProvider')]
    public function testChannelStatusDescribesState(int $state, string $description): void
    {
        $agi = $this->makeAgi(["200 result=$state"]);

        $this->assertSame($description, $agi->channel_status('SIP/9')['data']);
    }
}
