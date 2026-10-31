<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\ChapterProblems;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ChapterProblemsTest extends TestCase
{
    public function testAnUnreachableProviderIsServiceUnavailable(): void
    {
        $resolved = (new ChapterProblems())->resolve(new EmbedDescriptionUnreachableException('x'));

        self::assertNotNull($resolved);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $resolved->problem->status);
        self::assertFalse($resolved->isServerFault());
    }

    public function testOtherExceptionsPassThrough(): void
    {
        self::assertNull((new ChapterProblems())->resolve(new \RuntimeException('x')));
    }
}
