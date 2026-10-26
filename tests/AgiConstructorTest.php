<?php

namespace PhpAgi\Tests;

use PhpAgi\Tests\Support\ChildProcess;
use PHPUnit\Framework\TestCase;

/**
 * The constructor reads the AGI request header from the real STDIN, so it is exercised
 * in a child process (tests/fixtures/construct-agi.php).
 */
class AgiConstructorTest extends TestCase
{
    private const string HEADER = "agi_request: test.php\n"
        . "agi_channel: SIP/1000-00000001\n"
        . "agi_language: nl\n"
        . "agi_callerid: 1000\n"
        . "agi_calleridname: John Doe\n"
        . "agi_context: default\n"
        . "agi_extension: 200\n"
        . "agi_priority: 1\n"
        . "agi_enhanced: 0.0\n"
        . "agi_arg_1: some:value\n"
        . "\n";

    private function construct(string $stdin, ?string $configFile = null): array
    {
        $args = [sys_get_temp_dir()];
        if ($configFile !== null) {
            $args[] = $configFile;
        }
        $res = ChildProcess::run(__DIR__ . '/fixtures/construct-agi.php', $args, $stdin);

        $this->assertFalse($res['timedOut'], 'AGI constructor did not return');
        $this->assertSame(0, $res['exit'], $res['stderr']);

        return json_decode($res['stdout'], true);
    }

    public function testReadsRequestHeaderUntilBlankLine(): void
    {
        $result = $this->construct(self::HEADER . "200 result=0\n");

        $this->assertSame([
            'agi_request' => 'test.php',
            'agi_channel' => 'SIP/1000-00000001',
            'agi_language' => 'nl',
            'agi_callerid' => '1000',
            'agi_calleridname' => 'John Doe',
            'agi_context' => 'default',
            'agi_extension' => '200',
            'agi_priority' => '1',
            'agi_enhanced' => '0.0',
            'agi_arg_1' => 'some:value', // only the first ':' separates key and value
        ], $result['request']);
    }

    public function testFillsConfigDefaults(): void
    {
        $config = $this->construct(self::HEADER)['config'];

        $this->assertFalse($config['phpagi']['error_handler']); // from optconfig
        $this->assertSame(sys_get_temp_dir(), $config['phpagi']['tempdir']); // from optconfig
        $this->assertFalse($config['phpagi']['debug']);
        $this->assertNull($config['phpagi']['admin']);
        $this->assertArrayHasKey('text2wave', $config['festival']);
        $this->assertArrayHasKey('swift', $config['cepstral']);
    }

    public function testLoadsIniConfigFileAndOptconfigOverridesIt(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'phpagi');
        file_put_contents($file, "[phpagi]\ndebug=0\nadmin=ops@example.com\ntempdir=/ignored\n\n[festival]\ntext2wave=/opt/festival/text2wave\n");

        try {
            $config = $this->construct(self::HEADER, $file)['config'];
        } finally {
            unlink($file);
        }

        $this->assertSame('ops@example.com', $config['phpagi']['admin']);
        $this->assertSame('/opt/festival/text2wave', $config['festival']['text2wave']);
        $this->assertSame(sys_get_temp_dir(), $config['phpagi']['tempdir']);
    }
}
