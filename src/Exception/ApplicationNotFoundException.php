<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Exception;

/**
 * Thrown when EXEC targets a dialplan application that does not exist.
 */
class ApplicationNotFoundException extends CommandFailedException
{
}
