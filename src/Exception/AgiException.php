<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Exception;

/**
 * Abstract base for AGI client errors (connection, protocol, and command failures).
 */
abstract class AgiException extends \RuntimeException implements AgiThrowable
{
}
