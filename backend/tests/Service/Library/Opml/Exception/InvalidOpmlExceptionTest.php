<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml\Exception;

use App\Service\Opml\Exception\InvalidOpmlException;
use PHPUnit\Framework\TestCase;

final class InvalidOpmlExceptionTest extends TestCase
{
    public function testItCannotBeBuiltWithoutAMessage(): void
    {
        $constructor = new \ReflectionMethod(InvalidOpmlException::class, '__construct');

        self::assertSame(1, $constructor->getNumberOfRequiredParameters());
    }

    public function testItCarriesTheReasonTheUserReads(): void
    {
        $exception = new InvalidOpmlException('OPML has no <body>.');

        self::assertSame('OPML has no <body>.', $exception->getMessage());
    }
}
