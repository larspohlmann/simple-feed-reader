<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** The `api` firewall, asserted through real routes: GET /api/me, and GET /api/admin/users for ROLE_ADMIN. */
final class JwtAccessTest extends ApiTestCase
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
     * Revocation must bite on the very next request, as there are no refresh tokens: the api firewall keeps checking
     * status pre-auth, while the login firewall checks post-auth.
     */
    public function testSuspendingAUserRejectsTheirExistingToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('revoked@example.com');
        $token = $this->tokenFor($client, 'revoked@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        // Re-fetch through the current kernel's EntityManager: flushing the factory's entity, from a rebooted kernel,
        // would be a silent no-op and the test would pass without revoking anything.
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'revoked@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->suspend();
        $entityManager->flush();

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertUnauthorizedProblem($client);
    }

    /**
     * A stolen token must not learn why it stopped working. Assert the 401 first: /api/me echoes `status`, so the
     * string checks alone would pass against a 200.
     */
    public function testSuspendedTokenDoesNotLeakAccountStatus(): void
    {
        $client = self::createClient();
        $this->factory()->create('quiet@example.com');
        $token = $this->tokenFor($client, 'quiet@example.com');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'quiet@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->suspend();
        $entityManager->flush();

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->assertUnauthorizedProblem($client);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('suspended', $body);
        self::assertStringNotContainsString('accountStatus', $body);
        self::assertStringNotContainsString('not active', $body);
    }

    /**
     * Pins /api/me to an exact key set: a new column must not leak into the response (`passwordChangedAt` above
     * all), and removing `status` must fail here rather than hollow out the revocation test.
     */
    public function testMeExposesExactlyTheIntendedFields(): void
    {
        $client = self::createClient();
        $this->factory()->create('shape@example.com');
        $token = $this->tokenFor($client, 'shape@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        self::assertSame(
            [
                'ai',
                'createdAt',
                'email',
                'emailVerified',
                'id',
                'locale',
                'mail',
                'preferences',
                'roles',
                'status',
                'trialEndsAt',
            ],
            $this->sortedKeys($payload),
            'GET /api/me must expose exactly these fields — adding one is a deliberate act, not a side effect.',
        );
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_map(strval(...), array_keys($payload));
        sort($keys);

        return $keys;
    }

    /**
     * The `iat` boundary on both sides: strictly less-than, so a login in the reset's own second survives.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function issuedAtProvider(): iterable
    {
        yield 'one second before the change is revoked' => [-1, false];
        yield 'an hour before the change is revoked' => [-3600, false];
        yield 'the same second as the change survives' => [0, true];
        yield 'one second after the change survives' => [1, true];
        yield 'well after the change survives' => [10, true];
    }

    #[DataProvider('issuedAtProvider')]
    public function testTokensAreJudgedAgainstThePasswordChangeInstant(int $offsetSeconds, bool $shouldBeAccepted): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('boundary@example.com');

        // Whole seconds, ten in the past, so even the +1 case is a past `iat`: Lexik rejects a future one outright,
        // which would pass that case for the wrong reason.
        $changedAt = new \DateTimeImmutable('@' . (time() - 10));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->getRepository(User::class)->findOneBy(['email' => 'boundary@example.com']);
        self::assertInstanceOf(User::class, $stored);
        $stored->setPasswordHash($user->getPasswordHash(), $changedAt);
        $entityManager->flush();

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $token = $manager->createFromPayload($stored, ['iat' => $changedAt->getTimestamp() + $offsetSeconds]);

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        if ($shouldBeAccepted) {
            self::assertResponseIsSuccessful();

            return;
        }

        $this->assertUnauthorizedProblem($client);
    }

    /**
     * An account that has never recorded a password change revokes nothing.
     * This is what lets the migration be additive: rows written before the
     * column existed carry NULL, and a NULL must not lock anybody out.
     */
    public function testATokenSurvivesWhenNoPasswordChangeIsRecorded(): void
    {
        $client = self::createClient();
        $this->factory()->create('nostamp@example.com');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->getRepository(User::class)->findOneBy(['email' => 'nostamp@example.com']);
        self::assertInstanceOf(User::class, $stored);

        // Simulate a pre-migration row: hash present, stamp absent.
        $entityManager->getConnection()->executeStatement(
            'UPDATE app_user SET password_changed_at = NULL WHERE id = ?',
            [$stored->getId()],
        );
        $entityManager->clear();

        $token = $this->tokenFor($client, 'nostamp@example.com');
        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
    }

    public function testAdminRouteRejectsANonAdminToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('plain@example.com');
        $token = $this->tokenFor($client, 'plain@example.com');

        $client->request('GET', '/api/admin/users', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAdminRouteAcceptsAnAdminToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
        $token = $this->tokenFor($client, 'boss@example.com');

        $client->request('GET', '/api/admin/users', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
    }

    /**
     * The OAuth provider listing must be reachable without a token: the login page reads it first. Pinned here, with
     * the access_control order it depends on, so a rule inserted above `^/api/auth/` fails in this file.
     */
    public function testOAuthProviderListingIsReachableWithoutAToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/auth/oauth/providers');

        self::assertResponseIsSuccessful();
    }

    public function testAdminRouteRejectsAnonymousWithProblemJson(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/admin/users');

        $this->assertUnauthorizedProblem($client);
    }

    /**
     * An expired trial blocks the next request and flips the stored status, asserted through the firewall. The trial
     * expires after login, since LoginUserChecker's own trial check would refuse the login itself.
     */
    public function testExpiredTrialBlocksTheRequestAndFlipsStatusToSuspended(): void
    {
        $client = self::createClient();
        $this->factory()->create(
            'expired-trial@example.com',
            trialEndsAt: new \DateTimeImmutable('+7 days'),
        );
        $token = $this->tokenFor($client, 'expired-trial@example.com');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'expired-trial@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->setTrialEndsAt(new \DateTimeImmutable('-1 day'));
        $entityManager->flush();

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->assertUnauthorizedProblem($client);

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'expired-trial@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testActiveTrialInTheFutureIsAllowed(): void
    {
        $client = self::createClient();
        $this->factory()->create(
            'active-trial@example.com',
            trialEndsAt: new \DateTimeImmutable('+7 days'),
        );
        $token = $this->tokenFor($client, 'active-trial@example.com');

        $client->request('GET', self::PROTECTED, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
    }
}
