<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPUnit\Framework\TestCase;

final class CommentProseTest extends TestCase
{
    public function testAPhpstanIgnoreIsNotProseButItsReasonIs(): void
    {
        $prose = new CommentProse(new PhpDocTypeReader());

        self::assertSame(1, $prose->linesIn([
            '@phpstan-ignore arrayValues.list',
            '@phpstan-ignore method.notFound, argument.type',
            '@phpstan-ignore method.notFound (a reason)',
        ]));
    }
}
