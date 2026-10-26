<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Exception;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Exception\FeedUnreachableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class EmbedDescriptionUnreachableExceptionTest extends TestCase
{
    public function testNamesTheEmbedAndKeepsTheFetchFailureAsItsCause(): void
    {
        $cause = new FeedUnreachableException('reset');

        $exception = EmbedDescriptionUnreachableException::from($this->embed(), $cause);

        self::assertSame('The youtube description of dQw4w9WgXcQ did not answer.', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($cause, $exception->getPrevious());
    }

    public function testAnAnswerNamesTheEmbedAndItsStatus(): void
    {
        $exception = EmbedDescriptionUnreachableException::answered($this->embed(), Response::HTTP_BAD_GATEWAY);

        self::assertSame('The youtube description of dQw4w9WgXcQ answered 502.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    private function embed(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::YouTube, 'dQw4w9WgXcQ');
    }
}
