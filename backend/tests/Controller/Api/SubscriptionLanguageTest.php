<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Enum\FeedLanguage;
use App\Enum\SourceFormat;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SubscriptionLanguageTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testALanguageIsSetOnTheFeed(): void
    {
        $subscription = $this->subscriptionTo($this->feed('https://set.example.com/rss', null));

        $this->patch($subscription, ['language' => 'de']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('de', $this->returnedLanguage());
        self::assertSame(FeedLanguage::German, $this->storedLanguage($subscription));
    }

    public function testAutomaticClearsTheFeedsLanguage(): void
    {
        $subscription = $this->subscriptionTo($this->feed('https://clear.example.com/rss', FeedLanguage::English));

        $this->patch($subscription, ['language' => 'automatic']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertNull($this->returnedLanguage());
        self::assertNull($this->storedLanguage($subscription));
    }

    public function testAnOmittedLanguageLeavesTheFeedsLanguageAlone(): void
    {
        $subscription = $this->subscriptionTo($this->feed('https://keep.example.com/rss', FeedLanguage::Spanish));

        $this->patch($subscription, ['customTitle' => 'Kept']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('es', $this->returnedLanguage());
    }

    public function testANullLanguageLeavesTheFeedsLanguageAlone(): void
    {
        $subscription = $this->subscriptionTo($this->feed('https://null.example.com/rss', FeedLanguage::French));

        $this->patch($subscription, ['language' => null]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('fr', $this->returnedLanguage());
    }

    public function testTheLanguageChangesForEverySubscriber(): void
    {
        $feed = $this->feed('https://shared.example.com/rss', null);
        $mine = $this->subscriptionTo($feed);
        $theirs = $this->subscriptionTo($feed);

        $this->patch($mine, ['language' => 'fr']);

        $this->client->request('GET', '/api/subscriptions', server: $this->bearerFor($theirs->getUser()));
        $subscriptions = $this->payload($this->client)['subscriptions'] ?? null;
        self::assertIsArray($subscriptions);
        self::assertIsArray($subscriptions[0]);
        self::assertSame('fr', $subscriptions[0]['language']);
    }

    public function testAnUnknownLanguageIsRejected(): void
    {
        $subscription = $this->subscriptionTo($this->feed('https://unknown.example.com/rss', null));

        $this->patch($subscription, ['language' => 'it']);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->storedLanguage($subscription));
    }

    public function testASocialTimelineRefusesALanguage(): void
    {
        $feed = $this->feed('https://social.example.com/timeline', null);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);
        $subscription = $this->subscriptionTo($feed);

        $this->patch($subscription, ['customTitle' => 'Renamed', 'language' => 'de']);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_timeline_language', $this->payload($this->client)['type'] ?? null);
        self::assertNull($this->storedLanguage($subscription));
        self::assertNull($this->storedSubscription($subscription)->getCustomTitle());
    }

    private function feed(string $url, ?FeedLanguage $language): Feed
    {
        $feed = new Feed($url);
        $feed->recordTraits($feed->getTraits()->withLanguage($language));
        $this->entityManager()->persist($feed);

        return $feed;
    }

    private function subscriptionTo(Feed $feed): Subscription
    {
        $user = $this->factory()->create(uniqid('language-', true) . '@example.com');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager()->persist($subscription);
        $this->entityManager()->flush();

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function patch(Subscription $subscription, array $body): void
    {
        $this->client->request(
            'PATCH',
            '/api/subscriptions/' . $subscription->requireId(),
            server: $this->jsonBearerFor($subscription->getUser()),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function returnedLanguage(): mixed
    {
        $subscription = $this->payload($this->client)['subscription'] ?? null;
        self::assertIsArray($subscription);
        self::assertArrayHasKey('language', $subscription);

        return $subscription['language'];
    }

    private function storedLanguage(Subscription $subscription): ?FeedLanguage
    {
        return $this->storedSubscription($subscription)->getFeed()->getLanguage();
    }

    private function storedSubscription(Subscription $subscription): Subscription
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $stored = $entityManager->find(Subscription::class, $subscription->requireId());
        self::assertInstanceOf(Subscription::class, $stored);

        return $stored;
    }
}
