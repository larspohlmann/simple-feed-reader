<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The one-time passkey enrolment offer (#624) is recorded, not re-asked: once
 * an account answers it — in whatever way the client presents that choice —
 * `/api/me` must stop reporting it as unanswered.
 */
final class PasskeyOfferControllerTest extends ApiTestCase
{
    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    public function testTheProfileReportsTheOfferUnanswered(): void
    {
        $client = static::createClient();
        $this->factory()->create('offer-reader@example.test');
        $this->authenticate($client, 'offer-reader@example.test');

        $client->request('GET', '/api/me');

        self::assertFalse($this->passkeyOfferAnswered($client));
    }

    public function testAnsweringTheOfferIsRecordedAndIdempotent(): void
    {
        $client = static::createClient();
        $this->factory()->create('offer-reader-answer@example.test');
        $this->authenticate($client, 'offer-reader-answer@example.test');

        $client->request('POST', '/api/me/passkey-offer/answer');
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/me/passkey-offer/answer');
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me');
        self::assertTrue($this->passkeyOfferAnswered($client));
    }

    public function testAnAnonymousCallerCannotAnswerTheOffer(): void
    {
        static::createClient()->request('POST', '/api/me/passkey-offer/answer');

        self::assertResponseStatusCodeSame(401);
    }

    private function passkeyOfferAnswered(KernelBrowser $client): bool
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);
        self::assertIsBool($preferences['passkeyOfferAnswered']);

        return $preferences['passkeyOfferAnswered'];
    }
}
