<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Exception;

use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use PHPUnit\Framework\TestCase;

final class SocialWriteOutcomeUnknownExceptionTest extends TestCase
{
    public function testTheMessageNamesTheServiceAndTheCauseCarriesTheStatus(): void
    {
        $exception = SocialWriteOutcomeUnknownException::naming('Bluesky', 504);

        self::assertSame('Bluesky', $exception->serviceName);
        self::assertSame(504, $exception->status);
        self::assertSame(
            'Bluesky had trouble with this request, so it may or may not have gone through. Check before trying again.',
            $exception->getMessage(),
        );
        self::assertSame('Bluesky answered 504.', $exception->getPrevious()?->getMessage());
    }
}
