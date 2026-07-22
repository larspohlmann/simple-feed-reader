<?php

declare(strict_types=1);

namespace App\Tests\Exception\OAuth;

use App\Exception\ApiException;
use App\Exception\OAuth\OAuthException;
use App\Exception\OAuth\OAuthFailedException;
use App\Exception\OAuth\UnknownProviderException;
use App\Http\ApiProblem;
use PHPUnit\Framework\TestCase;

final class OAuthExceptionTest extends TestCase
{
    public function testUnknownProviderIs404(): void
    {
        $exception = new UnknownProviderException();

        self::assertInstanceOf(OAuthException::class, $exception);
        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('unknown_provider', $exception->type);
        self::assertSame(404, $exception->status);
        self::assertSame('Unknown sign-in provider', $exception->title);
    }

    public function testOAuthFailedIs502WithAGenericDetail(): void
    {
        $exception = new OAuthFailedException('token endpoint returned 400 invalid_grant');

        self::assertSame('oauth_failed', $exception->type);
        self::assertSame(502, $exception->status);
        self::assertSame('Sign-in failed', $exception->title);
        self::assertSame('Signing in with that provider did not work. Please try again.', $exception->detail);
        self::assertSame('token endpoint returned 400 invalid_grant', $exception->logDetail);
    }

    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \RuntimeException('Connection refused to oauth2.googleapis.com');
        $exception = new OAuthFailedException('network', $cause);

        self::assertSame($cause, $exception->getPrevious());
    }

    /**
     * The whole point of splitting $logDetail from $detail: whatever diagnostic
     * we record must not reach the client. Asserted against the document the
     * listener actually builds — it constructs ApiProblem from an
     * ApiException's five public properties, of which $logDetail is not one.
     */
    public function testNeitherTheLogDetailNorTheCauseReachesTheProblemDocument(): void
    {
        $exception = new OAuthFailedException(
            'audience mismatch: token aud=attacker-client-id',
            new \RuntimeException('private key /etc/secrets/apple.p8 unreadable'),
        );

        $problem = new ApiProblem(
            $exception->type,
            $exception->title,
            $exception->status,
            $exception->detail,
            $exception->errors,
        );

        $json = json_encode($problem->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('attacker-client-id', $json);
        self::assertStringNotContainsString('apple.p8', $json);
    }
}
