<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Exception;

use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\ExtractionFailure;
use PHPUnit\Framework\TestCase;

final class ArticleNotExtractedExceptionTest extends TestCase
{
    public function testCarriesAndNamesItsFailure(): void
    {
        $exception = new ArticleNotExtractedException(ExtractionFailure::Empty);

        self::assertSame(ExtractionFailure::Empty, $exception->failure);
        self::assertSame('The article was not extracted: empty.', $exception->getMessage());
    }
}
