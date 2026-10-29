<?php

namespace PhpAgi\Tests;

use AGI;
use PhpAgi\Tests\Support\AgiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * The derived helpers built on top of the raw commands: caller ID parsing, fast passing,
 * menus and the filesystem utilities.
 */
class AgiHelpersTest extends AgiTestCase
{
    public static function callerIdProvider(): array
    {
        $empty = ['name' => '', 'protocol' => '', 'username' => '', 'host' => '', 'port' => ''];

        return [
            'number only' => ['1000', array_merge($empty, ['username' => '1000'])],
            'name and number' => ['"John Doe" <1000>', array_merge($empty, ['name' => 'John Doe', 'username' => '1000'])],
            'single quoted name' => ["'Jane' <2000>", array_merge($empty, ['name' => 'Jane', 'username' => '2000'])],
            'sip uri' => ['"Bob" <sip:bob@example.com>', array_merge($empty, ['name' => 'Bob', 'protocol' => 'sip', 'username' => 'bob', 'host' => 'example.com'])],
            'sip uri with port' => ['<sip:bob@10.0.0.1:5060>', array_merge($empty, ['protocol' => 'sip', 'username' => 'bob', 'host' => '10.0.0.1', 'port' => '5060'])],
        ];
    }

    #[DataProvider('callerIdProvider')]
    public function testParseCallerId(string $callerid, array $expected): void
    {
        $this->assertSame($expected, $this->makeAgi()->parse_callerid($callerid));
    }

    public function testParseCallerIdDefaultsToRequestCallerId(): void
    {
        $agi = $this->makeAgi();
        $agi->request['agi_callerid'] = '"Alice" <3000>';

        $this->assertSame('Alice', $agi->parse_callerid()['name']);
        $this->assertSame('3000', $agi->parse_callerid()['username']);
    }

    public function testFastpassPlaysWhenBufferEmptyAndRecordsPressedDigit(): void
    {
        $agi = $this->makeAgi(['200 result=' . ord('5')]);
        $buffer = '';

        $agi->fastpass_say_digits($buffer, '123', '0123456789');

        $this->assertSame('5', $buffer);
        $this->assertSent($agi, 'SAY DIGITS 123 "0123456789"');
    }

    public function testFastpassSkipsPlaybackWhenBufferEndsInEscapeDigit(): void
    {
        $agi = $this->makeAgi();
        $buffer = '5';

        $res = $agi->fastpass_stream_file($buffer, 'demo', '0123456789');

        $this->assertSame(['code' => AGIRES_OK, 'result' => ord('5'), 'endpos' => 0], $res);
        $this->assertSent($agi);
    }

    public function testFastpassPlaysWhenBufferEndsInNonEscapeDigit(): void
    {
        $agi = $this->makeAgi(['200 result=0']);
        $buffer = '#';

        $agi->fastpass_say_number($buffer, 7, '0123456789');

        $this->assertSame('#', $buffer);
        $this->assertSent($agi, 'SAY NUMBER 7 "0123456789"');
    }

    public function testFastpassGetDataWithEmptyBufferUsesGetData(): void
    {
        $agi = $this->makeAgi(['200 result=1234']);
        $buffer = '';

        $res = $agi->fastpass_get_data($buffer, 'enter-pin', 3000, 4);

        $this->assertSame('1234', $buffer);
        $this->assertSame('200', $res['code']);
        $this->assertSent($agi, 'GET DATA enter-pin 3000 4');
    }

    public function testFastpassGetDataCollectsRemainingDigitsUntilPound(): void
    {
        $agi = $this->makeAgi(['200 result=' . ord('2'), '200 result=' . ord('#')]);
        $buffer = '1';

        $res = $agi->fastpass_get_data($buffer, 'enter-pin', 3000, 4);

        $this->assertSame(['code' => AGIRES_OK, 'result' => '12'], $res);
        $this->assertSent($agi, 'WAIT FOR DIGIT -1', 'WAIT FOR DIGIT -1');
    }

    public function testMenuReturnsKeyPressedDuringPrompt(): void
    {
        $agi = $this->makeAgi(['200 result=0 endpos=1', '200 result=' . ord('2') . ' endpos=10']);

        $choice = $agi->menu(['1' => 'press-1-for-sales', '2' => 'press-2-for-support']);

        $this->assertSame('2', $choice);
        $this->assertSent($agi, 'STREAM FILE press-1-for-sales "12" 0', 'STREAM FILE press-2-for-support "12" 0');
    }

    public function testMenuFallsBackToGetDataAfterPrompts(): void
    {
        $agi = $this->makeAgi(['200 result=0 endpos=1', '200 result=1']);

        $this->assertSame('1', $agi->menu(['1' => 'press-1'], 1500));
        $this->assertSent($agi, 'STREAM FILE press-1 "1" 0', 'GET DATA beep 1500 1');
    }

    public function testMenuReturnsMinusOneOnHangup(): void
    {
        $agi = $this->makeAgi(['200 result=-1']);

        $this->assertSame(-1, $agi->menu(['1' => 'press-1']));
    }

    public function testMakeFolderCreatesNestedDirectories(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpagi-test-' . uniqid();
        $target = $base . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';

        try {
            $this->assertTrue($this->makeAgi()->make_folder($target));
            $this->assertDirectoryExists($target);
        } finally {
            @rmdir($target);
            @rmdir(dirname($target));
            @rmdir($base);
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testWhichFindsExecutableInGivenPath(): void
    {
        // which() splits the path on ':', so it only works with Unix-style paths.
        $agi = $this->makeAgi();
        $self = PHP_BINARY;

        $this->assertSame(
            dirname($self) . '/' . basename($self),
            $agi->which(basename($self), dirname($self))
        );
        $this->assertFalse($agi->which('definitely-not-a-real-binary', dirname($self)));
    }

    public function testConlogSendsVerboseOnlyInDebugMode(): void
    {
        $agi = $this->makeAgi(['200 result=1']);

        $agi->conlog('quiet');
        $this->assertSent($agi);

        $agi->config['phpagi']['debug'] = true;
        $agi->conlog('loud', 2);
        $this->assertSent($agi, 'VERBOSE "loud" 2');
    }

    public function testNewAsteriskManagerSharesConfigAndLogsThroughAgi(): void
    {
        $agi = $this->makeAgi();
        // new_AsteriskManager() always passes a null config path, so the host's
        // /etc/asterisk/phpagi.conf may load; only assert values set here or always filled in.
        $agi->config['asmanager'] = ['server' => 'pbx.example.com', 'port' => 5039];

        $manager = $agi->new_AsteriskManager();

        $this->assertSame($manager, $agi->asmanager);
        $this->assertSame($agi, $manager->pagi);
        $this->assertSame('pbx.example.com', $agi->config['asmanager']['server']);
        $this->assertSame(5039, $agi->config['asmanager']['port']);
        $this->assertArrayHasKey('username', $agi->config['asmanager']);

        $manager->config['asmanager']['server'] = 'other.example.com';
        $this->assertSame('other.example.com', $agi->config['asmanager']['server'], 'config is shared by reference');
    }
}
