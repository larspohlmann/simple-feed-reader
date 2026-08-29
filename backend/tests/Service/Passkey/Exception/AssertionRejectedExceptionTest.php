<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\AssertionRejectedException;
use PHPUnit\Framework\TestCase;

/**
 * PasskeyAuthenticator always rewrites this into a plain AuthenticationException
 * before it can reach a client — see this exception's own docblock — so
 * nothing else in the suite ever observes its $status/$title/$detail. That
 * makes this class's own constructor wiring untested by every other test in
 * the feature, not merely lightly covered.
 */
final class AssertionRejectedExceptionTest extends TestCase
{
    public function testItIsA401WithAFixedTitleAndDetail(): void
    {
        $exception = new AssertionRejectedException();

        self::assertSame('passkey_assertion_rejected', $exception->type);
        self::assertSame(401, $exception->status);
        self::assertSame('Passkey login rejected', $exception->title);
        self::assertSame('The passkey could not be verified.', $exception->detail);
    }
}
