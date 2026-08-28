<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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
}
