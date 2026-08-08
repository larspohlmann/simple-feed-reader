<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function seedFeedWithEntries(User $user, int $count): Subscription
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $feed->setFaviconUrl('https://icon.example.com/f.png');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $em->persist($sub);

        for ($i = 1; $i <= $count; $i++) {
            $e = new Entry(
                $feed,
                "g$i",
                "https://example.com/$i",
                "Post $i",
                new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            );
            $e->setPublishedAt(new \DateTimeImmutable(sprintf('2026-07-%02dT00:00:00Z', $i)));
            $em->persist($e);
        }
        $em->flush();

        return $sub;
    }

    private function seedDebugSettings(User $user, bool $enabled): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: $enabled,
        ));
        $em->persist($settings);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries');
        self::assertResponseStatusCodeSame(401);
    }

    public function testListsNewestFirstWithState(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-list@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(3, $body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame('Post 3', $first['title']);
        self::assertFalse($first['isRead']);
        self::assertFalse($first['isViewed']);
        self::assertSame('Seeded', $first['source']);
        self::assertSame('https://icon.example.com/f.png', $first['faviconUrl']);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertNull($body['nextCursor']);
    }

    public function testExposesThePersistedImageOnEachEntry(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-image@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = new Feed('https://example.com/img-feed.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $july1 = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $july2 = new \DateTimeImmutable('2026-07-02T00:00:00Z');
        $withImage = new Entry($feed, 'img-1', 'https://example.com/1', 'Post', $july1);
        $withImage->setImage('https://i.example.com/big.jpg', 948, 474);
        $em->persist($withImage);
        $em->persist(new Entry($feed, 'img-2', 'https://example.com/2', 'Post 2', $july2));
        $em->flush();

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        // Newest first: img-2 (no image), then img-1 (with image).
        [$second, $first] = $body['entries'];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('https://i.example.com/big.jpg', $first['imageUrl']);
        self::assertSame(948, $first['imageWidth']);
        self::assertSame(474, $first['imageHeight']);
        self::assertNull($second['imageUrl']);
        self::assertNull($second['imageWidth']);
        self::assertNull($second['imageHeight']);
    }

    public function testPaginatesWithCursor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-page@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries?limit=2', server: $headers);
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page1);
        self::assertIsArray($page1['entries']);
        self::assertCount(2, $page1['entries']);
        self::assertIsString($page1['nextCursor']);

        $client->request(
            'GET',
            '/api/entries?limit=2&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page2);
        self::assertIsArray($page2['entries']);
        self::assertCount(1, $page2['entries']);
        $firstOfPage2 = $page2['entries'][0];
        self::assertIsArray($firstOfPage2);
        self::assertSame('Post 1', $firstOfPage2['title']);
        self::assertNull($page2['nextCursor']);
    }

    public function testRejectsUnknownView(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-view@example.com');

        $client->request('GET', '/api/entries?view=bogus', server: $headers);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']); // uniform with every other invalid field
        self::assertIsArray($body['errors']);
        self::assertSame(
            ['view' => ['Unknown view. Use one of: all, unread, favorites, kept, for-you.']],
            $body['errors'],
        );
    }

    public function testEveryNamedViewIsAccepted(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-view-all@example.com');
        $this->seedFeedWithEntries($user, 1);

        foreach (['all', 'unread', 'favorites', 'kept', 'for-you'] as $view) {
            $client->request('GET', "/api/entries?view=$view", server: $headers);
            self::assertResponseIsSuccessful("view=$view should be accepted");
        }
    }

    public function testForYouViewReturnsRecommendedEntriesWithReason(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou@example.com');
        $sub = $this->seedFeedWithEntries($user, 2);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entry = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed(), 'guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $em->persist($run);
        $em->persist(new RecommendationItem($run, $entry, 1, 'Matches your interest in g1', 77));
        $em->flush();

        $client->request('GET', '/api/entries?view=for-you', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(1, $body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame('Post 1', $first['title']);
        self::assertSame('Matches your interest in g1', $first['recommendationReason']);
        self::assertArrayNotHasKey('recommendationScore', $first);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertNull($body['nextCursor']);
    }

    public function testForYouViewIncludesTheRecommendationScoreOnlyWhenDebugIsEnabled(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-debug@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entry = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed(), 'guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $em->persist($run);
        $em->persist(new RecommendationItem($run, $entry, 1, 'Matches your interest in g1', 42));
        $this->seedDebugSettings($user, true);
        $em->flush();

        $client->request('GET', '/api/entries?view=for-you', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame(42, $first['recommendationScore']);
    }

    public function testForYouViewPaginatesWithCursor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-page@example.com');
        $sub = $this->seedFeedWithEntries($user, 3);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entries = $em->getRepository(Entry::class)->findBy(['feed' => $sub->getFeed()], ['guid' => 'ASC']);
        self::assertCount(3, $entries);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $em->persist($run);
        foreach ($entries as $position => $entry) {
            $em->persist(new RecommendationItem($run, $entry, $position + 1, "reason $position"));
        }
        $em->flush();

        $client->request('GET', '/api/entries?view=for-you&limit=2', server: $headers);
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page1);
        self::assertIsArray($page1['entries']);
        self::assertCount(2, $page1['entries']);
        self::assertIsString($page1['nextCursor']);

        $client->request(
            'GET',
            '/api/entries?view=for-you&limit=2&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page2);
        self::assertIsArray($page2['entries']);
        self::assertCount(1, $page2['entries']);
        self::assertNull($page2['nextCursor']);
    }

    public function testForYouViewWithAMalformedCursorDegradesToTheFirstPageInsteadOfErroring(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-badcursor@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entry = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $em->persist($run);
        $em->persist(new RecommendationItem($run, $entry, 1, 'reason'));
        $em->flush();

        // A stale/garbled cursor from an old session must degrade to the
        // first page — the for-you view never validates its cursor the way
        // the main list does, unlike EntryCursor's strict 422.
        $client->request('GET', '/api/entries?view=for-you&cursor=not-a-cursor', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(1, $body['entries']);
    }

    public function testInvalidCursorIsRejected(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-cursor@example.com');

        $client->request('GET', '/api/entries?cursor=not-a-cursor', server: $headers);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('cursor', $body['errors']);
    }

    public function testPatchStateLazilyCreatesAndReturnsState(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-patch@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true, 'isFavorite' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isRead']);
        self::assertTrue($body['state']['isFavorite']);
        self::assertFalse($body['state']['isKept']);
        self::assertNotNull($body['state']['readAt']);
    }

    public function testPatchStateUnreadClearsReadAt(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unread@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true], \JSON_THROW_ON_ERROR),
        );
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => false], \JSON_THROW_ON_ERROR),
        );
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertFalse($body['state']['isRead']);
        self::assertNull($body['state']['readAt']);
    }

    public function testPatchStateMarksViewed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isViewed']);
        self::assertNotNull($body['state']['viewedAt']);
        self::assertFalse($body['state']['isRead']);
    }

    public function testPatchStateRejectsUnviewing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unview@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":false}',
        );

        // The client type-switches on the problem type and the offending field,
        // so a bare 422 is not the contract.
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('isViewed', $body['errors']);
    }

    public function testMarkingViewedKeepsAWatermarkReadEntryRead(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed-watermark@example.com');
        $sub = $this->seedFeedWithEntries($user, 3);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->unreadCountOf($client, $headers, (int) $sub->getId()));

        // The sweep leaves these entries sparse: they are read by the
        // watermark alone. Materialising a state row must not resurrect them.
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isViewed']);
        self::assertTrue($body['state']['isRead']);
        self::assertSame('2026-08-01T00:00:00+00:00', $body['state']['readAt']);

        $client->request('GET', '/api/entries', server: $headers);
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['entries']);
        foreach ($list['entries'] as $entry) {
            self::assertIsArray($entry);
            self::assertTrue($entry['isRead'], 'Every swept entry must stay read.');
        }

        self::assertSame(0, $this->unreadCountOf($client, $headers, (int) $sub->getId()));
    }

    public function testMarkingViewedSeedsReadOnlyUpToTheWatermark(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed-boundary@example.com');
        $sub = $this->seedFeedWithEntries($user, 3);

        // Sweep to exactly the second entry's date: entry 2 is read (the
        // watermark is inclusive), entry 3 is not.
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-07-02T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $onTheWatermark = $this->entryIdOf($sub, 'g2');
        $aboveTheWatermark = $this->entryIdOf($sub, 'g3');

        self::assertTrue($this->markViewed($client, $headers, $onTheWatermark)['isRead']);

        $above = $this->markViewed($client, $headers, $aboveTheWatermark);
        self::assertFalse($above['isRead']);
        self::assertNull($above['readAt']);
    }

    private function entryIdOf(Subscription $subscription, string $guid): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entry = $em->getRepository(Entry::class)->findOneBy([
            'feed' => $subscription->getFeed(),
            'guid' => $guid,
        ]);
        self::assertInstanceOf(Entry::class, $entry);

        $id = $entry->getId();
        if (null === $id) {
            self::fail("The seeded entry $guid has no id.");
        }

        return $id;
    }

    /**
     * @param array<string,string> $headers
     *
     * @return array<string,mixed> the state the API reports back
     */
    private function markViewed(KernelBrowser $client, array $headers, int $entryId): array
    {
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string,mixed> $state */
        $state = $body['state'];

        return $state;
    }

    /**
     * @param array<string,string> $headers
     */
    private function unreadCountOf(KernelBrowser $client, array $headers, int $subscriptionId): int
    {
        $client->request('GET', '/api/subscriptions', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscriptions']);
        foreach ($body['subscriptions'] as $subscription) {
            self::assertIsArray($subscription);
            if ($subscription['id'] === $subscriptionId) {
                self::assertIsInt($subscription['unreadCount']);

                return $subscription['unreadCount'];
            }
        }

        self::fail("No subscription $subscriptionId in the list.");
    }

    public function testViewedSurvivesOtherStatePatches(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed-keep@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isRead":true,"isFavorite":true}',
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isViewed']);
        self::assertNotNull($body['state']['viewedAt']);
    }

    public function testCannotPatchEntryOfUnsubscribedFeed(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-idor@example.com');
        [, $stranger] = $this->auth('e-owner@example.com');
        $strangerSub = $this->seedFeedWithEntries($stranger, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $strangerSub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testGetReturnsOwnedEntry(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-get@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);
        self::assertSame($entryId, $body['entry']['id']);
        self::assertSame('Post 1', $body['entry']['title']);
        self::assertSame('Seeded', $body['entry']['source']);
        self::assertFalse($body['entry']['isRead']);
    }

    public function testGetUnsubscribedEntryIs404(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-get-idor@example.com');
        [, $stranger] = $this->auth('e-get-owner@example.com');
        $strangerSub = $this->seedFeedWithEntries($stranger, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $strangerSub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetMissingEntryIs404(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-get-missing@example.com');

        $client->request('GET', '/api/entries/99999999', server: $headers);

        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkReadAllThenListUnreadIsEmpty(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markread@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(0, $body['entries']);
    }

    public function testMarkReadRejectsBadTimestamp(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbad@example.com');
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => 'nonsense'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadFeedScopeWithoutIdIsUniformValidationError(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-marknoid@example.com');
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'feed', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        // A missing required id reports the same validation_error the client
        // switches on for every other bad field — not a bare 400 request_error.
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('id', $body['errors']);
    }
}
