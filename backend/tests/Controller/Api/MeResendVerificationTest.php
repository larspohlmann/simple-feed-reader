<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnablesMailInTests;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Self-service reissue of the address-verification mail (#636). Safe to call
 * repeatedly: RegistrationService::resendVerification() is a no-op once the
 * address is proven, so this endpoint never queues a second mail for an
 * already-verified account.
 *
 * Both tests authenticate an Active account, never PendingVerification: the
 * API firewall's UserChecker rejects a non-Active status on every request
 * (see App\Security\UserChecker), so a PendingVerification account can never
 * hold a usable bearer token in the first place. The account this endpoint
 * exists for is the one the spec calls out — an Active account whose address
 * is unverified because mail was off at registration and got turned on later.
 */
final class MeResendVerificationTest extends ApiTestCase
{
    use EnablesMailInTests;

    protected function setUp(): void
    {
        // Same reasoning as MeDigestTestControllerTest: the resend_verification
        // limiter counts in a FILESYSTEM pool that outlives the run, so a prior
        // case's spend must not bleed into this one and trip a spurious 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, User $user): void
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    private function resendVerification(KernelBrowser $client): void
    {
        $client->request('POST', '/api/me/resend-verification');
    }

    public function testAnUnverifiedAccountGetsAFreshVerificationMail(): void
    {
        $client = static::createClient();
        $this->seedEnabledMailInstance();
        $user = $this->factory()->create('unverified@example.test');
        $this->authenticate($client, $user);

        $this->resendVerification($client);

        self::assertResponseStatusCodeSame(204);
        self::assertEmailCount(1);
    }

    public function testAnAlreadyVerifiedAccountGetsNoNewMail(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('verified@example.test');
        $user->markEmailVerified(new \DateTimeImmutable('2026-08-01 10:00:00'));
        $this->em()->flush();
        $this->authenticate($client, $user);

        $this->resendVerification($client);

        self::assertResponseStatusCodeSame(204);
        self::assertEmailCount(0);
    }

    public function testResendingVerificationRequiresAuthentication(): void
    {
        $client = static::createClient();

        $this->resendVerification($client);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * An unverified account can spam only up to the resend_verification budget
     * before being refused, so it cannot turn its own inbox (and the relay)
     * into an unlimited mail sink.
     */
    public function testTheSixthCallInTheWindowIsThrottled(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('resend-throttled@example.test');
        $this->authenticate($client, $user);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->resendVerification($client);
            self::assertResponseStatusCodeSame(204, \sprintf('attempt %d should still be accepted', $attempt));
        }

        $this->resendVerification($client);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('rate_limited', $this->payload($client)['type']);
    }
}
