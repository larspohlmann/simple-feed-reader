<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class FeedDirectoryControllerTest extends ApiTestCase
{
    public function testTheIndexListsTheCountries(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-index@example.test');

        $client->request('GET', '/api/feed-directory/index');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $index = json_decode((string) $client->getInternalResponse()->getContent(), true);
        self::assertIsArray($index);
        self::assertIsArray($index['countries']);
        self::assertSame(['AT', 'DE'], array_column($index['countries'], 'code'));
    }

    public function testACountryFileIsStreamedGzipped(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-country@example.test');

        $client->request('GET', '/api/feed-directory/countries/DE');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Encoding', 'gzip');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHasHeader('ETag');
        $body = gzdecode((string) $client->getInternalResponse()->getContent());
        self::assertIsString($body);
        self::assertStringStartsWith('{"country":"DE"', $body);
    }

    public function testAMatchingETagAnswersNotModified(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-etag@example.test');
        $client->request('GET', '/api/feed-directory/countries/DE');
        $etag = (string) $client->getResponse()->headers->get('ETag');

        $client->request('GET', '/api/feed-directory/countries/DE', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
    }

    public function testAnUnknownCountryIsNotFound(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-unknown@example.test');

        $client->request('GET', '/api/feed-directory/countries/FR');

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/feed-directory/index');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSubscribedFeedsComeBackNormalisedWithTheirHosts(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'directory-subscribed@example.test');
        $this->subscribe($user, 'https://www.example.de/feed/');
        $this->subscribe($user, 'http://podcasts.example.at/rss');

        $client->request('GET', '/api/feed-directory/subscribed');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'urls' => ['example.de/feed', 'podcasts.example.at/rss'],
                'hosts' => ['example.de', 'podcasts.example.at'],
            ],
            $this->payload($client),
        );
    }

    public function testAnotherUsersSubscriptionsAreNotListed(): void
    {
        $client = self::createClient();
        $other = $this->factory()->create('directory-other@example.test');
        $this->subscribe($other, 'https://example.de/feed');
        $this->signIn($client, 'directory-mine@example.test');

        $client->request('GET', '/api/feed-directory/subscribed');

        self::assertSame(['urls' => [], 'hosts' => []], $this->payload($client));
    }

    private function subscribe(User $user, string $url): void
    {
        $feed = new Feed($url);
        $this->entityManager()->persist($feed);
        $subscribedAt = new \DateTimeImmutable('2026-07-01 00:00:00');
        $this->entityManager()->persist(new Subscription($user, $feed, $subscribedAt));
        $this->entityManager()->flush();
    }
}
