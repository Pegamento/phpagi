<?php

declare(strict_types=1);

namespace Pegamento\PhpAgi\Tests;

use Pegamento\PhpAgi\Exception\AgiException;
use Pegamento\PhpAgi\Exception\AgiThrowable;
use Pegamento\PhpAgi\Exception\ApplicationNotFoundException;
use Pegamento\PhpAgi\Exception\ChannelHungUpException;
use Pegamento\PhpAgi\Exception\CommandFailedException;
use Pegamento\PhpAgi\Exception\ConnectionException;
use Pegamento\PhpAgi\Exception\InvalidCommandException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function exceptionClasses(): array
    {
        return [
            AgiException::class,
            ConnectionException::class,
            InvalidCommandException::class,
            CommandFailedException::class,
            ApplicationNotFoundException::class,
            ChannelHungUpException::class,
        ];
    }

    public function testChannelHungUpExceptionIsAgiThrowableButNotAgiException(): void
    {
        $reflection = new ReflectionClass(ChannelHungUpException::class);

        self::assertContains(
            AgiThrowable::class,
            $reflection->getInterfaceNames(),
        );
        $parent = get_parent_class(ChannelHungUpException::class);
        self::assertNotFalse($parent);
        self::assertSame(\RuntimeException::class, $parent);
    }

    public function testAgiExceptionIsAbstractRuntimeExceptionImplementingAgiThrowable(): void
    {
        $reflection = new ReflectionClass(AgiException::class);

        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isSubclassOf(\RuntimeException::class));
        self::assertContains(AgiThrowable::class, $reflection->getInterfaceNames());
    }

    /**
     * @return iterable<string, array{0: class-string, 1: class-string}>
     */
    public static function directParentProvider(): iterable
    {
        yield 'application not found' => [ApplicationNotFoundException::class, CommandFailedException::class];
        yield 'connection' => [ConnectionException::class, AgiException::class];
        yield 'invalid command' => [InvalidCommandException::class, AgiException::class];
    }

    /**
     * @param class-string $class
     * @param class-string $expectedParent
     */
    #[DataProvider('directParentProvider')]
    public function testDirectParent(string $class, string $expectedParent): void
    {
        $parent = get_parent_class($class);
        self::assertNotFalse($parent);
        self::assertSame($expectedParent, $parent);
    }

    public function testCommandFailedExceptionExposesCommandAndChannel(): void
    {
        $exception = new CommandFailedException('STREAM FILE "foo"', 'SIP/100-00000001', 'playback failed');

        self::assertSame('STREAM FILE "foo"', $exception->command);
        self::assertSame('SIP/100-00000001', $exception->channel);
    }

    public function testChannelHungUpExceptionExposesCommandChannelAndUniqueId(): void
    {
        $exception = new ChannelHungUpException('CHANNEL STATUS', 'SIP/100-00000001', '1234567890.1');

        self::assertSame('CHANNEL STATUS', $exception->command);
        self::assertSame('SIP/100-00000001', $exception->channel);
        self::assertSame('1234567890.1', $exception->uniqueId);
    }

    public function testNoExceptionClassIsFinal(): void
    {
        foreach ($this->exceptionClasses() as $class) {
            self::assertFalse(
                (new ReflectionClass($class))->isFinal(),
                sprintf('%s must not be final', $class),
            );
        }
    }
}
