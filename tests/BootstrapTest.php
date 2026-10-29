<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL);
    }

    public function testErrorReportingIsAll(): void
    {
        self::assertSame(E_ALL, error_reporting());
    }
}
