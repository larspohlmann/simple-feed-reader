<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Exception;

use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use PHPUnit\Framework\TestCase;

final class SocialNetworkUnreachableExceptionTest extends TestCase
{
    public function testFromKeepsTheMessageAndChainsTheCause(): void
    {
        $cause = new SocialPostNotFoundException('The post is gone.');

        $exception = SocialNetworkUnreachableException::from($cause);

        self::assertSame('The post is gone.', $exception->getMessage());
        self::assertSame($cause, $exception->getPrevious());
    }
}
