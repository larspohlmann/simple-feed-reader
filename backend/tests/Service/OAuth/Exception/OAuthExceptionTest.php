<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Exception;

use App\Service\OAuth\Exception\OAuthException;
use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Exception\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class OAuthExceptionTest extends TestCase
{
    public function testBothFailuresBelongToTheOAuthFamily(): void
    {
        self::assertInstanceOf(OAuthException::class, new UnknownProviderException());
        self::assertInstanceOf(OAuthException::class, new OAuthFailedException('network'));
    }

    public function testTheLogDetailStaysOutOfTheMessage(): void
    {
        $exception = new OAuthFailedException('token endpoint returned 400 invalid_grant');

        self::assertSame('token endpoint returned 400 invalid_grant', $exception->logDetail);
        self::assertStringNotContainsString('invalid_grant', $exception->getMessage());
    }

    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \RuntimeException('Connection refused to oauth2.googleapis.com');

        self::assertSame($cause, (new OAuthFailedException('network', $cause))->getPrevious());
    }
}
