<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\ImageProblems;
use App\Service\Image\Exception\ImageRefusedException;
use App\Service\Image\Exception\ImageUnavailableException;
use App\Service\Image\Exception\InvalidImageUrlException;
use PHPUnit\Framework\TestCase;

final class ImageProblemsTest extends TestCase
{
    public function testMapsTheImageExceptionsBelow500(): void
    {
        $problems = new ImageProblems();

        self::assertSame(400, $problems->resolve(new InvalidImageUrlException('x'))?->problem->status);
        self::assertSame(404, $problems->resolve(new ImageUnavailableException('x'))?->problem->status);
        self::assertSame(404, $problems->resolve(new ImageRefusedException('x'))?->problem->status);
        self::assertNull($problems->resolve(new \RuntimeException('x')));
    }
}
