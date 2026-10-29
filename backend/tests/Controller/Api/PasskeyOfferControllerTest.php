<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The one-time passkey enrolment offer is recorded, not re-asked: once an account answers it, in whatever way the
 * client presents that choice, `/api/me` must stop reporting it as unanswered.
 */
final class PasskeyOfferControllerTest extends ApiTestCase
{
    public function testTheProfileReportsTheOfferUnanswered(): void
    {
        $client = static::createClient();
        $this->authenticate($client, $this->factory()->create('offer-reader@example.test'));

        $client->request('GET', '/api/me');

        self::assertFalse($this->passkeyOfferAnswered($client));
    }

    public function testAnsweringTheOfferIsRecordedAndIdempotent(): void
    {
        $client = static::createClient();
        $this->authenticate($client, $this->factory()->create('offer-reader-answer@example.test'));

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
