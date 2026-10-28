<?php
/**
 * Child process for AgiConstructorTest: builds a real AGI object, which reads its
 * request header from STDIN, and prints what it ended up with as JSON.
 *
 * Usage: php construct-agi.php <tempdir> [config-file]
 * Without a config file the empty fixture is used, so the host's
 * /etc/asterisk/phpagi.conf is never loaded.
 */
require dirname(__DIR__) . '/bootstrap.php';

$agi = new AGI($argv[2] ?? PhpAgi\Tests\Support\EMPTY_CONFIG, ['error_handler' => false, 'tempdir' => $argv[1]]);

echo json_encode(['request' => $agi->request, 'config' => $agi->config]);
