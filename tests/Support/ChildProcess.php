<?php

namespace PhpAgi\Tests\Support;

/**
 * Runs a PHP script in a separate process with the given STDIN, for code that reads
 * the real STDIN (such as the AGI constructor).
 *
 * Output goes to temp files rather than pipes: Windows pipes ignore non-blocking mode,
 * so reading them would block past the timeout when the child hangs.
 */
final class ChildProcess
{
    /**
     * @return array{exit: int|null, stdout: string, stderr: string, timedOut: bool}
     */
    public static function run(string $script, array $args, string $stdin, float $timeout = 10.0): array
    {
        $stdoutFile = tempnam(sys_get_temp_dir(), 'phpagi-out');
        $stderrFile = tempnam(sys_get_temp_dir(), 'phpagi-err');

        $cmd = array_merge([PHP_BINARY, $script], $args);
        $proc = proc_open($cmd, [['pipe', 'r'], ['file', $stdoutFile, 'w'], ['file', $stderrFile, 'w']], $pipes);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        while (($status = proc_get_status($proc))['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $timedOut = true;
                break;
            }
            usleep(20000);
        }
        proc_close($proc);

        $result = [
            'exit' => $timedOut ? null : $status['exitcode'],
            'stdout' => file_get_contents($stdoutFile),
            'stderr' => file_get_contents($stderrFile),
            'timedOut' => $timedOut,
        ];
        @unlink($stdoutFile);
        @unlink($stderrFile);

        return $result;
    }
}
