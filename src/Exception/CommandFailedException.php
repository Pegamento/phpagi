<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Exception;

/**
 * Thrown when a command was accepted and ran but reported failure.
 */
class CommandFailedException extends AgiException
{
    /**
     * The AGI command line that failed.
     */
    public readonly string $command;

    /**
     * The channel name from the AGI environment when the command ran.
     */
    public readonly string $channel;

    /**
     * @param string $command  The AGI command line that failed
     * @param string $channel  The channel name from the AGI environment
     * @param string $message  Human-readable failure description
     */
    public function __construct(string $command, string $channel, string $message)
    {
        $this->command = $command;
        $this->channel = $channel;

        parent::__construct($message);
    }
}
