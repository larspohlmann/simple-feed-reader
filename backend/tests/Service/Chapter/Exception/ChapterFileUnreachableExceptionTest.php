<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Exception;

use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use PHPUnit\Framework\TestCase;

final class ChapterFileUnreachableExceptionTest extends TestCase
{
    public function testNamesTheFileAndKeepsTheFetchFailureAsItsCause(): void
    {
        $cause = new FeedUnreachableException('reset');

        $exception = ChapterFileUnreachableException::from('https://cdn.test/ch.json', $cause);

        self::assertSame('Chapters file https://cdn.test/ch.json did not answer.', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($cause, $exception->getPrevious());
    }
}
