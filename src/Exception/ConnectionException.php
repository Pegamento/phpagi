<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Exception;

/**
 * Thrown when the AGI transport cannot read or write (including EOF).
 */
class ConnectionException extends AgiException
{
}
