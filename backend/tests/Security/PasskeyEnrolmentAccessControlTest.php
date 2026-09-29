<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMap;

/**
 * The enrolment paths sit under the public `^/api/auth/`, and the first access_control match wins. This asks the
 * real `security.access_map`, as AccessListener does, rather than sending requests, so it tests the rules even for
 * a path whose route would answer first.
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
     * /passkey/login stays public: a discoverable login knows no account until the assertion returns. A rule as broad
     * as `^/api/auth/passkey/register` catching it is the prefix accident this test is for.
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
