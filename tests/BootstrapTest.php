<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testErrorReportingIsAll(): void
    {
        self::assertSame(E_ALL, PEGAMENTO_PHPAGI_BOOTSTRAP_ERROR_REPORTING);
    }
}
