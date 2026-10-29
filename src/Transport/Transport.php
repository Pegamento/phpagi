<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Transport;

use Pegamento\PhpAgi\Exception\ConnectionException;

/**
 * Bidirectional line-oriented I/O for an AGI session.
 *
 * Implementations send and receive one logical line per call.
 */
interface Transport
{
    /**
     * Write one AGI command. Implementations append a single newline.
     *
     * @throws ConnectionException when the write fails
     */
    public function writeLine(string $command): void;

    /**
     * Read one line and return it without the trailing newline.
     *
     * @throws ConnectionException on EOF or any other read failure
     */
    public function readLine(): string;
}
