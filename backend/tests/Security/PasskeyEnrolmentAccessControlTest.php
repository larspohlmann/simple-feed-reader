<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMap;

/**
 * Every passkey enrolment path sits under `^/api/auth/`, which access_control
 * already makes PUBLIC_ACCESS, and the first matching rule wins (#624). Two
 * rules added above that line are meant to close this off for all four
 * enrolment paths — including the `{id}` form of the delete route — before
 * their controllers exist.
 *
 * This queries the real `security.access_map` service directly instead of
 * making an HTTP request through the kernel. That is deliberate, not a
 * shortcut: `/passkey/register`, `/passkeys` and `/passkeys/{id}` have no
 * controller until Tasks 7 and 8 land, and a path with no matching route 404s
 * before the security layer ever runs — `bin/console debug:event-dispatcher
 * kernel.request` shows `RouterListener` at priority 32 firing before the
 * firewall at priority 8, and the router's `NotFoundHttpException` stops the
 * `kernel.request` event right there. An HTTP-level test of those three paths
 * would therefore see 404 today regardless of whether the access_control fix
 * is even present, proving nothing. Querying `AccessMap::getPatterns()` —
 * exactly what the firewall's own `AccessListener` calls — tests the
 * configuration itself, so it is meaningful for a path whose controller does
 * not exist yet and stays meaningful once it does.
 */
final class PasskeyEnrolmentAccessControlTest extends KernelTestCase
{
    public function testEveryEnrolmentPathRequiresFullAuthentication(): void
    {
        self::bootKernel();
        /** @var AccessMap $accessMap */
        $accessMap = self::getContainer()->get('security.access_map');

        foreach (
            [
            ['POST', '/api/auth/passkey/register/options'],
            ['POST', '/api/auth/passkey/register'],
            ['GET', '/api/auth/passkeys'],
            ['DELETE', '/api/auth/passkeys/1'],
            ] as [$method, $path]
        ) {
            [$roles] = $accessMap->getPatterns(Request::create($path, $method));

            self::assertSame(
                ['IS_AUTHENTICATED_FULLY'],
                $roles,
                sprintf('%s %s must resolve to an authenticated access_control rule', $method, $path),
            );
        }
    }

    /**
     * /passkey/login stays public on purpose — spec §4.2, the login flow is
     * discoverable-credential only, so the server does not know an account
     * until the assertion comes back. A rule as broad as
     * `^/api/auth/passkey/register` matching it too would be exactly the kind
     * of prefix accident this whole test exists to catch.
     */
    public function testTheLoginPathStaysPublic(): void
    {
        self::bootKernel();
        /** @var AccessMap $accessMap */
        $accessMap = self::getContainer()->get('security.access_map');

        [$roles] = $accessMap->getPatterns(Request::create('/api/auth/passkey/login', 'POST'));

        self::assertSame(['PUBLIC_ACCESS'], $roles);
    }
}
