<?php
/**
 * Child process for AgiConstructorTest: builds a real AGI object, which reads its
 * request header from STDIN, and prints what it ended up with as JSON.
 *
 * Usage: php construct-agi.php <tempdir> [config-file]
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$agi = new AGI($argv[2] ?? null, ['error_handler' => false, 'tempdir' => $argv[1]]);

echo json_encode(['request' => $agi->request, 'config' => $agi->config]);
