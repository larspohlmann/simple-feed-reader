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
 * actually ships. The ROLE_ADMIN rule is asserted through GET /api/admin/users,
 * which replaced the test-only probe route once the admin queue existed.
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
     *
     * The 401 assertion comes FIRST, and its absence is what made an earlier
     * version of this test worthless. With only the string checks below, the
     * whole test passed against a 200 for a user who was never suspended: it
     * "held" purely because /api/me happens to echo a `status` field whose
     * value for a live account is `active` rather than `suspended`. It was
     * therefore asserting a property of the success payload while claiming to
     * guard revocation, and would have gone completely silent the moment that
     * field was renamed or dropped. Prove the request was refused, then prove
     * the refusal says nothing.
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

        $this->assertUnauthorizedProblem($client);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('suspended', $body);
        self::assertStringNotContainsString('accountStatus', $body);
        self::assertStringNotContainsString('not active', $body);
    }

    /**
     * Pins GET /api/me to an EXACT key set, the way the admin listing is pinned
     * in AdminUserControllerTest.
     *
     * Two reasons. The controller is hand-built precisely so a column added
     * later cannot leak into the response — but "hand-built" is a convention,
     * and nothing enforced it, so adding one line here would have shipped a new
     * field silently. `passwordChangedAt`, added in this very branch, is
     * exactly the kind of field that must never appear.
     *
     * Second, this is the fragility that made the revocation test above
     * vacuous: that test leaned on `status` existing in the success payload.
     * Anyone removing the field now fails HERE, loudly and on purpose, instead
     * of quietly hollowing out a security assertion elsewhere.
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
            ['createdAt', 'email', 'id', 'roles', 'status'],
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
     * The `iat` vs passwordChangedAt boundary, pinned on both sides.
     *
     * The comparison must be STRICTLY less-than. `iat` is a whole-second UNIX
     * timestamp, so a user who resets their password and immediately signs back
     * in routinely gets a token stamped in the same second as the change. Under
     * `<=` that token would be refused and password reset would appear broken
     * to the very person who just completed it — a self-inflicted lockout in
     * the recovery flow. Under `<` it is honoured, and only genuinely earlier
     * tokens die.
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

    #[\PHPUnit\Framework\Attributes\DataProvider('issuedAtProvider')]
    public function testTokensAreJudgedAgainstThePasswordChangeInstant(int $offsetSeconds, bool $shouldBeAccepted): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('boundary@example.com');

        // A whole-second instant, so the offsets below are exact rather than
        // rounded across a sub-second boundary. Anchored ten seconds in the
        // PAST so that even the +1 case yields an `iat` that is still in the
        // past: Lexik rejects a future-dated token outright (LoadedJWS checks
        // iat > now), which would make that case pass for the wrong reason.
        $changedAt = new \DateTimeImmutable('@' . (time() - 10));

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $stored = $em->getRepository(User::class)->findOneBy(['email' => 'boundary@example.com']);
        self::assertInstanceOf(User::class, $stored);
        $stored->setPasswordHash($user->getPasswordHash(), $changedAt);
        $em->flush();

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

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $stored = $em->getRepository(User::class)->findOneBy(['email' => 'nostamp@example.com']);
        self::assertInstanceOf(User::class, $stored);

        // Simulate a pre-migration row: hash present, stamp absent.
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET password_changed_at = NULL WHERE id = ?',
            [$stored->getId()],
        );
        $em->clear();

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

    public function testAdminRouteRejectsAnonymousWithProblemJson(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/admin/users');

        $this->assertUnauthorizedProblem($client);
    }
}
