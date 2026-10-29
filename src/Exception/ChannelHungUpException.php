<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Exception;

/**
 * Thrown when the channel is no longer available; control-flow, not a transport or protocol error.
 */
class ChannelHungUpException extends \RuntimeException implements AgiThrowable
{
    /**
     * The AGI command that detected or triggered the hangup.
     */
    public readonly string $command;

    /**
     * The channel name from the AGI environment.
     */
    public readonly string $channel;

    /**
     * The channel unique id from the AGI environment.
     */
    public readonly string $uniqueId;

    /**
     * @param string $command   The AGI command associated with the hangup
     * @param string $channel   The channel name from the AGI environment
     * @param string $uniqueId  The channel unique id from the AGI environment
     */
    public function __construct(string $command, string $channel, string $uniqueId)
    {
        $this->command = $command;
        $this->channel = $channel;
        $this->uniqueId = $uniqueId;

        parent::__construct(sprintf('Channel hung up during command: %s', $command));
    }
}
