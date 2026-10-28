<?php

namespace PhpAgi\Tests\Support;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * An empty config file. Pass it to constructors so they don't fall back to
 * /etc/asterisk/phpagi.conf on machines that have Asterisk installed.
 */
const EMPTY_CONFIG = __DIR__ . '/fixtures/empty.ini';
