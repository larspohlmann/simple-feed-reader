<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Exception;

use App\Service\Social\Social\Exception\SocialNetworkUnavailableException;
use PHPUnit\Framework\TestCase;

final class SocialNetworkUnavailableExceptionTest extends TestCase
{
    public function testTheMessageNamesTheServiceAndTheCauseCarriesTheStatus(): void
    {
        $exception = SocialNetworkUnavailableException::naming('lemmy.world', 503);

        self::assertSame('lemmy.world', $exception->serviceName);
        self::assertSame(
            "lemmy.world isn't responding right now — it's probably overloaded. Try again in a minute.",
            $exception->getMessage(),
        );
        self::assertSame('lemmy.world answered 503.', $exception->getPrevious()?->getMessage());
    }
}
