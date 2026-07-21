<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The `api` firewall's behaviour, asserted through GET /api/me — a real
 * protected route, so these guarantees are pinned against something that
 * actually ships. The ROLE_ADMIN rule has no real endpoint yet and is still
 * asserted through App\Tests\Support\Http\ProtectedProbeController.
 */
final class JwtAccessTest extends WebTestCase
{
    private const PROTECTED = '/api/me';

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        /** @var CacheItemPoolInterface $rateLimiterCache */
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    private function tokenFor(KernelBrowser $client, string $email): string
    {
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => 'correct-horse-battery']),
        );
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['token'] ?? null, 'Login should have produced a token.');

        return $decoded['token'];
    }

    private function assertUnauthorizedProblem(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        self::assertSame('unauthorized', $decoded['type']);
        // Lexik's native shape must be gone entirely.
        self::assertArrayNotHasKey('code', $decoded);
        self::assertArrayNotHasKey('message', $decoded);
    }

    public function testValidTokenReachesTheController(): void
    {
        $client = self::createClient();
        $this->factory()->create('holder@example.com');
        $token = $this->tokenFor($client, 'holder@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('holder@example.com', $payload['email']);
    }

    public function testMissingTokenIsProblemJson(): void
    {
        $client = self::createClient();

        $client->request('GET', self::PROTECTED);

        $this->assertUnauthorizedProblem($client);
    }

    public function testMalformedTokenIsProblemJson(): void
    {
        $client = self::createClient();

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-jwt']);

        $this->assertUnauthorizedProblem($client);
    }

    public function testExpiredTokenIsProblemJson(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('expired@example.com');

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $expired = $manager->createFromPayload($user, ['exp' => time() - 3600]);

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $expired]);

        $this->assertUnauthorizedProblem($client);
    }

    /**
     * Revocation must take effect on the very next request — that is the whole
     * reason there are no refresh tokens. This is also the regression the
     * split user checkers could have caused: the login firewall moved to
     * checkPostAuth, but the api firewall must keep checking in checkPreAuth,
     * where a JWT request has no credentials step to hang the check on.
     */
    public function testSuspendingAUserRejectsTheirExistingToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('revoked@example.com');
        $token = $this->tokenFor($client, 'revoked@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        // Re-fetch through the CURRENT kernel's EntityManager: the instance the
        // factory used belongs to a kernel that has since been rebooted, so
        // flushing the stale entity would be a silent no-op and this test would
        // pass without ever revoking anything.
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'revoked@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->setStatus(UserStatus::Suspended);
        $em->flush();

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertUnauthorizedProblem($client);
    }

    /**
     * A stolen token must not tell the thief why it stopped working. The
     * account status is disclosed at login only, after a verified password.
     */
    public function testSuspendedTokenDoesNotLeakAccountStatus(): void
    {
        $client = self::createClient();
        $this->factory()->create('quiet@example.com');
        $token = $this->tokenFor($client, 'quiet@example.com');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'quiet@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->setStatus(UserStatus::Suspended);
        $em->flush();

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('suspended', $body);
        self::assertStringNotContainsString('accountStatus', $body);
        self::assertStringNotContainsString('not active', $body);
    }

    public function testAdminRouteRejectsANonAdminToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('plain@example.com');
        $token = $this->tokenFor($client, 'plain@example.com');

        $client->request('GET', '/api/admin/_probe', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAdminRouteAcceptsAnAdminToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
        $token = $this->tokenFor($client, 'boss@example.com');

        $client->request('GET', '/api/admin/_probe', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
    }

    public function testAdminRouteRejectsAnonymousWithProblemJson(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/admin/_probe');

        $this->assertUnauthorizedProblem($client);
    }
}
