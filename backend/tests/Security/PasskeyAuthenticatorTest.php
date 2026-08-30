<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasskeyAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * supports() is redundant belt-and-suspenders in the real request cycle: the
 * `passkey_login` firewall's own `pattern` already restricts it to
 * `/api/auth/passkey/login`, and the route behind it declares
 * `methods: ['POST']`, enforced by RouterListener BEFORE the firewall ever
 * runs (confirmed: `debug:event-dispatcher kernel.request` puts RouterListener
 * at priority 32, FirewallListener at 8 — router wins). So a GET to this path
 * always 405s before this class's supports() is ever consulted, and this
 * class is the only authenticator on its firewall. That makes both operands
 * of the AND always true whenever this code runs for real — this unit test
 * exists to keep the method's OWN logic correct in isolation, not to claim
 * the real pipeline can ever hand it a mismatched request.
 *
 * Built via reflection rather than the full container: every constructor
 * dependency is `final`, and supports() reads none of them.
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
