<?php

namespace PhpAgi\Tests;

use PhpAgi\Tests\Support\AgiTestCase;

/**
 * evaluate() is the single path every AGI command goes through: it writes the command
 * and parses Asterisk's reply into ['code', 'result', 'data', ...extra key=value tokens].
 */
class AgiEvaluateTest extends AgiTestCase
{
    public function testWritesCommandTrimmedWithNewline(): void
    {
        $agi = $this->makeAgi(['200 result=0']);

        $agi->evaluate("  ANSWER \n");

        $this->assertSent($agi, 'ANSWER');
    }

    public function testParsesSimpleSuccess(): void
    {
        $agi = $this->makeAgi(['200 result=1']);

        $this->assertSame(['code' => '200', 'result' => '1', 'data' => ''], $agi->evaluate('ANSWER'));
    }

    public function testParsesParenthesisedData(): void
    {
        $agi = $this->makeAgi(['200 result=1 (timeout)']);

        $res = $agi->evaluate('GET DATA beep');

        $this->assertSame('1', $res['result']);
        $this->assertSame('timeout', $res['data']);
    }

    public function testParsesParenthesisedDataContainingSpaces(): void
    {
        $agi = $this->makeAgi(['200 result=1 (SIP/1000-00000001 is up)']);

        $this->assertSame('SIP/1000-00000001 is up', $agi->evaluate('GET VARIABLE CHANNEL')['data']);
    }

    public function testParsesExtraKeyValueTokens(): void
    {
        $agi = $this->makeAgi(['200 result=0 endpos=12345']);

        $res = $agi->evaluate('STREAM FILE demo ""');

        $this->assertSame('0', $res['result']);
        $this->assertSame('12345', $res['endpos']);
        $this->assertSame('', $res['data']);
    }

    public function testCombinesKeyValueAndParenthesisedData(): void
    {
        $agi = $this->makeAgi(['200 result=48 (dtmf) endpos=8000']);

        $res = $agi->evaluate('RECORD FILE x wav "#" -1');

        $this->assertSame('48', $res['result']);
        $this->assertSame('dtmf', $res['data']);
        $this->assertSame('8000', $res['endpos']);
    }

    public function testErrorCodeKeepsRawTextAsData(): void
    {
        $agi = $this->makeAgi(['510 Invalid or unknown command']);

        $this->assertSame(
            ['code' => '510', 'result' => null, 'data' => 'Invalid or unknown command'],
            $agi->evaluate('BOGUS')
        );
    }

    public function testParsesMultiLineErrorResponse(): void
    {
        $agi = $this->makeAgi([
            '520-Invalid command syntax.  Proper usage follows:',
            'Usage: ANSWER',
            '520 End of proper usage.',
        ]);

        $res = $agi->evaluate('ANSWER extra');

        $this->assertSame('520', $res['code']);
        $this->assertSame("Invalid command syntax.  Proper usage follows:\nUsage: ANSWER\n", $res['data']);
    }

    public function testSkipsStrayBlankLinesBeforeReply(): void
    {
        $agi = $this->makeAgi(['', '', '200 result=1']);

        $this->assertSame('1', $agi->evaluate('SEND TEXT "x"')['result']);
    }

    public function testReturnsBrokenWhenAsteriskStopsAnswering(): void
    {
        $agi = $this->makeAgi([]); // EOF: every read returns false

        $this->assertSame(['code' => 500, 'result' => -1, 'data' => ''], $agi->evaluate('ANSWER'));
    }

    public function testReturnsBrokenWhenOutputCannotBeWritten(): void
    {
        $agi = $this->makeAgi(['200 result=1']);
        $agi->out = fopen('php://memory', 'r'); // read-only: fwrite fails

        $this->assertSame(['code' => 500, 'result' => -1, 'data' => ''], $agi->evaluate('ANSWER'));
    }

    public function testResultIsReturnedAsString(): void
    {
        // Callers compare with == throughout the library; pin the type so a change is deliberate.
        $agi = $this->makeAgi(['200 result=-1']);

        $this->assertSame('-1', $agi->evaluate('ANSWER')['result']);
    }
}
