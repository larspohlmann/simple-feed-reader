<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader\Exception;

use App\Service\Content\Reader\Exception\ArticleNotExtractedException;
use App\Service\Content\Reader\Model\ExtractionFailure;
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
