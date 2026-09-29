<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasskeyAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * supports() in isolation: in the real pipeline the firewall's pattern and the route's POST-only method already
 * guarantee both operands. Built by reflection, since supports() reads no dependency.
 */
final class PasskeyAuthenticatorTest extends TestCase
{
    private PasskeyAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = (new \ReflectionClass(PasskeyAuthenticator::class))
            ->newInstanceWithoutConstructor();
    }

    public function testSupportsAPostToTheExactLoginPath(): void
    {
        self::assertTrue($this->authenticator->supports(
            Request::create('/api/auth/passkey/login', 'POST'),
        ));
    }

    public function testDoesNotSupportAGetToTheLoginPath(): void
    {
        self::assertFalse($this->authenticator->supports(
            Request::create('/api/auth/passkey/login', 'GET'),
        ));
    }

    public function testDoesNotSupportAPostToADifferentPath(): void
    {
        self::assertFalse($this->authenticator->supports(
            Request::create('/api/auth/passkey/login/options', 'POST'),
        ));
    }
}
