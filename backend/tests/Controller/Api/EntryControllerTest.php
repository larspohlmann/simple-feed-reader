<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Dto\Entry\MarkEntriesReadRequest;
use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CommentsLoad;
use App\Repository\EntryStateRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($entityManager, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function seedFeedWithEntries(User $user, int $count): Subscription
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $feed->setFaviconUrl('https://icon.example.com/f.png');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $entityManager->persist($subscription);

        for ($index = 1; $index <= $count; $index++) {
            $publishedAt = new \DateTimeImmutable(sprintf('2026-07-%02dT00:00:00Z', $index));
            $entry = new Entry(
                $feed,
                "g$index",
                "https://example.com/$index",
                "Post $index",
                new \DateTimeImmutable('2026-07-01T00:00:00Z'),
                $publishedAt,
            );
            $entry->setPublishedAt($publishedAt);
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        return $subscription;
    }

    /**
     * A single-entry feed sharing $urlHash with another feed seeded the same
     * way, for asserting the cross-feed duplicate-collapse footer.
     */
    private function seedFeedWithMatchingEntry(
        User $user,
        string $feedTitle,
        string $urlHash,
        \DateTimeImmutable $effectiveDate,
    ): Entry {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $feed = new Feed('https://example.com/dup-feed-' . uniqid('', true) . '.xml');
        $feed->setTitle($feedTitle);
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'dup-guid-' . uniqid('', true),
            'https://tagesschau.de/x',
            $feedTitle . ' entry',
            $effectiveDate,
            $effectiveDate,
            $urlHash,
        );
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function seedEntryWithDiscussion(User $user, Discussion $discussion): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/discussion-feed.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $july1 = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, 'discussion-1', 'https://example.com/1', 'Post', $july1, $july1);
        $entry->setDiscussion($discussion);
        $entityManager->persist($entry);
        $entityManager->flush();
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        return $entryId;
    }

    private function seedDebugEnabledSettings(User $user): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        (new RecommendationRunFixtures($entityManager, $cipher))->debugEnabledSettings($user);
    }

    private function seedShowReasonsSettings(User $user): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        (new RecommendationRunFixtures($entityManager, $cipher))->showReasonsEnabledSettings($user);
    }

    private function seedSavedSearchMembership(User $user, string $term, Entry $entry): SavedSearch
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $search = new SavedSearch($user, $term, false);
        $entityManager->persist($search);
        $entityManager->flush();
        $search->setSlug($search->getId() . '-' . $term);
        $entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')));
        $entityManager->flush();

        return $search;
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
        self::assertFalse($first['isHidden']);
        self::assertFalse($first['isViewed']);
        self::assertSame('Seeded', $first['source']);
        self::assertSame('https://icon.example.com/f.png', $first['faviconUrl']);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertNull($body['nextCursor']);
    }

    public function testEntryListCarriesSavedSearchMembership(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-list-saved-search@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);
        $search = $this->seedSavedSearchMembership($user, 'post', $entry);

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame(
            [['id' => $search->getId(), 'slug' => $search->getSlug(), 'term' => $search->getTerm()]],
            $first['savedSearches'],
        );
    }

    public function testListEntriesCarryAnExcerptButNoContentHtml(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-excerpt-list@example.com');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/excerpt-feed.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $july1 = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, 'excerpt-1', 'https://example.com/1', 'Post', $july1, $july1);
        $entry->setContentHtml('<p>The full body of the article.</p>');
        $entityManager->persist($entry);
        $entityManager->flush();

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertArrayNotHasKey('contentHtml', $first);
        self::assertSame('The full body of the article.', $first['excerpt']);
    }

    public function testExposesThePersistedImageOnEachEntry(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-image@example.com');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/img-feed.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $july1 = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $july2 = new \DateTimeImmutable('2026-07-02T00:00:00Z');
        $withImage = new Entry($feed, 'img-1', 'https://example.com/1', 'Post', $july1, $july1);
        $withImage->getImage()->storePending('https://i.example.com/big.jpg', 948, 474);
        $entityManager->persist($withImage);
        $entityManager->persist(new Entry($feed, 'img-2', 'https://example.com/2', 'Post 2', $july2, $july2));
        $entityManager->flush();

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

    public function testEntryDuplicatesNameTheOtherFeedAsSource(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-duplicates@example.com');

        $earlier = new \DateTimeImmutable('2026-07-05T09:00:00Z');
        $later = new \DateTimeImmutable('2026-07-05T10:00:00Z');
        $this->seedFeedWithMatchingEntry($user, 'Feed A', 'urlhash-dup-x', $earlier);
        $this->seedFeedWithMatchingEntry($user, 'Feed B', 'urlhash-dup-x', $later);

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(1, $body['entries']);
        $entry = $body['entries'][0];
        self::assertIsArray($entry);
        self::assertIsArray($entry['duplicates']);
        self::assertCount(1, $entry['duplicates']);
        $duplicate = $entry['duplicates'][0];
        self::assertIsArray($duplicate);
        self::assertSame('Feed B', $duplicate['source']);
        self::assertArrayNotHasKey('contentHtml', $duplicate);
        self::assertArrayHasKey('excerpt', $duplicate);
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

    public function testPaginatesWithoutSkippingOrRepeatingWhenEntriesShareAnEffectiveDate(): void
    {
        // A whole refresh run shares one effective date, so the id embedded in
        // the cursor is the only tiebreaker: if it defaulted to 0 instead of
        // the real row id, the tied group would skip or repeat across pages.
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-tied-cursor@example.com');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/tied-feed.xml');
        $feed->setTitle('Tied');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $tied = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($index = 1; $index <= 3; $index++) {
            $entityManager->persist(
                new Entry($feed, "tied-$index", "https://example.com/tied-$index", "Tied $index", $tied, $tied),
            );
        }
        $entityManager->flush();

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

        $titles = array_map(
            static function (mixed $entry): mixed {
                self::assertIsArray($entry);

                return $entry['title'];
            },
            [...$page1['entries'], ...$page2['entries']],
        );
        sort($titles);
        self::assertSame(['Tied 1', 'Tied 2', 'Tied 3'], $titles);
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
            ['view' => ['Unknown view. Use one of: all, unread, favorites, kept, viewed, for-you.']],
            $body['errors'],
        );
    }

    public function testListsOldestFirstWhenAskedAndPagesOnward(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-oldest@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries?order=asc&limit=2', server: $headers);
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page1);
        self::assertIsArray($page1['entries']);
        self::assertSame(['Post 1', 'Post 2'], array_column($page1['entries'], 'title'));
        self::assertIsString($page1['nextCursor']);

        $client->request(
            'GET',
            '/api/entries?order=asc&limit=2&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page2);
        self::assertIsArray($page2['entries']);
        self::assertSame(['Post 3'], array_column($page2['entries'], 'title'));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownOrderUrlProvider(): iterable
    {
        yield 'a date-ordered view' => ['/api/entries?order=up'];
        yield 'the for-you view, which ignores a valid order' => ['/api/entries?view=for-you&order=up'];
    }

    #[DataProvider('unknownOrderUrlProvider')]
    public function testRejectsAnUnknownOrder(string $url): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-order@example.com');

        $client->request('GET', $url, server: $headers);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertSame(['order' => ['Unknown order. Use one of: desc, asc.']], $body['errors']);
    }

    public function testTheForYouViewAcceptsAnOrderItDoesNotApply(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-order-for-you@example.com');

        $client->request('GET', '/api/entries?view=for-you&order=asc', server: $headers);

        self::assertResponseIsSuccessful();
    }

    public function testEveryNamedViewIsAccepted(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-view-all@example.com');
        $this->seedFeedWithEntries($user, 1);

        foreach (['all', 'unread', 'favorites', 'kept', 'viewed', 'for-you'] as $view) {
            $client->request('GET', "/api/entries?view=$view", server: $headers);
            self::assertResponseIsSuccessful("view=$view should be accepted");
        }
    }

    public function testMarkingAnEntryUnreadClearsItsViewedFlag(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unview@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);
        $id = $entry->getId();

        // Opening the entry marks it read and viewed.
        $client->request(
            'PATCH',
            "/api/entries/$id/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true, 'isViewed' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/entries?view=viewed', server: $headers);
        $viewed = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($viewed);
        self::assertIsArray($viewed['entries']);
        self::assertCount(1, $viewed['entries'], 'The opened entry belongs in the viewed list.');

        // Marking it unread must drop it back out of the viewed list.
        $client->request(
            'PATCH',
            "/api/entries/$id/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/entries?view=viewed', server: $headers);
        $afterUnread = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($afterUnread);
        self::assertIsArray($afterUnread['entries']);
        self::assertCount(0, $afterUnread['entries'], 'Marking unread clears the viewed flag (#478).');
    }

    public function testForYouViewOmitsBothDebugAnnotationsWhenDebugIsOff(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou@example.com');
        $subscription = $this->seedFeedWithEntries($user, 2);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);
        $entry->setContentHtml('<p>For-you body text.</p>');

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        $entityManager->persist(new RecommendationItem($run, $entry, 1, 'Matches your interest in g1', 77));
        $entityManager->flush();

        $client->request('GET', '/api/entries?view=for-you', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(1, $body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame('Post 1', $first['title']);
        self::assertArrayNotHasKey('contentHtml', $first);
        self::assertSame('For-you body text.', $first['excerpt']);
        // Debug off hides both the score and the reason (#342): the reason used
        // to show with the score suppressed, which read as inconsistent.
        self::assertArrayNotHasKey('recommendationReason', $first);
        self::assertArrayNotHasKey('recommendationScore', $first);
        // The run identity and generation time ARE sent with debug off — the
        // run-boundary divider is a normal-user feature (#348).
        self::assertSame($run->getId(), $first['runId']);
        self::assertSame('2026-08-07T09:05:00+00:00', $first['runGeneratedAt']);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertNull($body['nextCursor']);
    }

    public function testForYouViewNarrowsToUnreadPicksWhenAsked(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-unread@example.com');
        $subscription = $this->seedFeedWithEntries($user, 2);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entries = $entityManager
            ->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        foreach ($entries as $position => $entry) {
            $entityManager->persist(new RecommendationItem($run, $entry, $position + 1, 'reason', 50));
        }
        $entityManager->flush();

        $client->request(
            'PATCH',
            '/api/entries/' . $entries[0]->getId() . '/state',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/entries?view=for-you', server: $headers);
        self::assertCount(2, $this->entriesOf($client), 'The unfiltered feed keeps a read pick.');

        $client->request('GET', '/api/entries?view=for-you&unread=1', server: $headers);
        $unread = $this->entriesOf($client);
        self::assertCount(1, $unread);
        $first = $unread[0];
        self::assertIsArray($first);
        self::assertSame($entries[1]->getId(), $first['id']);
    }

    public function testMarkForYouReadClearsThePicksWithoutMovingTheWatermark(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-mark@example.com');
        $subscription = $this->seedFeedWithEntries($user, 2);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entries = $entityManager
            ->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);

        // Only the first entry is recommended: the second proves the action
        // stays inside the for-you list instead of clearing the whole feed.
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        $entityManager->persist(new RecommendationItem($run, $entries[0], 1, 'reason', 50));
        $entityManager->flush();

        $client->request(
            'POST',
            '/api/entries/for-you/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['until' => '2026-08-07T12:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=for-you&unread=1', server: $headers);
        self::assertCount(0, $this->entriesOf($client), 'Every pick is read now.');

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        $stillUnread = $this->entriesOf($client);
        self::assertCount(1, $stillUnread, 'The entry that was never recommended stays unread.');

        // The watermark is what emptied the candidate pool in #665; a for-you
        // mark-read must never touch it.
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(Subscription::class)->find($subscription->getId());
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertNull($reloaded->getMarkedReadUntil());
    }

    /** @return list<mixed> */
    private function entriesOf(KernelBrowser $client): array
    {
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);

        return array_values($body['entries']);
    }

    public function testForYouViewWithholdsBothAnnotationsFromDebugAlone(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-debug@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        $entityManager->persist(new RecommendationItem($run, $entry, 1, 'Matches your interest in g1', 42));
        $this->seedDebugEnabledSettings($user);
        $entityManager->flush();

        $client->request('GET', '/api/entries?view=for-you', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        // Debug keeps the per-run call logs and reaches nothing in the feed
        // (#576): it is not a second way to reveal an explanation the reader
        // asked to keep hidden, not even half of one.
        self::assertArrayNotHasKey('recommendationReason', $first);
        self::assertArrayNotHasKey('recommendationScore', $first);
    }

    public function testForYouViewIncludesTheReasonAndItsScoreWhenShowReasonsIsEnabled(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-reasons@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        $entityManager->persist(new RecommendationItem($run, $entry, 1, 'Matches your interest in g1', 42));
        $this->seedShowReasonsSettings($user);
        $entityManager->flush();

        $client->request('GET', '/api/entries?view=for-you', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        // showReasons on, debug off: the reason and the score beside it both
        // show — one explanation, one switch (#576).
        self::assertSame('Matches your interest in g1', $first['recommendationReason']);
        self::assertSame(42, $first['recommendationScore']);
    }

    public function testForYouViewPaginatesWithCursor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-foryou-page@example.com');
        $subscription = $this->seedFeedWithEntries($user, 3);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entries = $entityManager
            ->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);
        self::assertCount(3, $entries);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        foreach ($entries as $position => $entry) {
            $entityManager->persist(new RecommendationItem($run, $entry, $position + 1, "reason $position"));
        }
        $entityManager->flush();

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
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        $entityManager->persist(new RecommendationItem($run, $entry, 1, 'reason'));
        $entityManager->flush();

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
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true, 'isFavorite' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isHidden']);
        self::assertTrue($body['state']['isFavorite']);
        self::assertFalse($body['state']['isKept']);
        self::assertNotNull($body['state']['hiddenAt']);
    }

    public function testPatchStateUnreadClearsReadAt(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unread@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true], \JSON_THROW_ON_ERROR),
        );
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => false], \JSON_THROW_ON_ERROR),
        );
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertFalse($body['state']['isHidden']);
        self::assertNull($body['state']['hiddenAt']);
    }

    public function testPatchStateMarksViewed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
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
        // Viewing reads the entry (#482 subset invariant, enforced on flush).
        self::assertTrue($body['state']['isHidden']);
    }

    public function testPatchStateUnviewingKeepsTheEntryRead(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unview@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
        self::assertNotNull($entryId);

        // Viewing reads the entry (the subset invariant).
        $viewed = $this->markViewed($client, $headers, (int) $entryId);
        self::assertTrue($viewed['isViewed']);
        self::assertTrue($viewed['isHidden']);

        // Un-ticking (#482) clears viewed but leaves the entry read — hiding from
        // the unread list is sticky.
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":false}',
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertFalse($body['state']['isViewed']);
        self::assertTrue($body['state']['isHidden']);
    }

    public function testMarkingViewedKeepsAWatermarkReadEntryRead(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed-watermark@example.com');
        $subscription = $this->seedFeedWithEntries($user, 3);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->unreadCountOf($client, $headers, $subscription->requireId()));

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
        self::assertTrue($body['state']['isHidden']);
        self::assertSame('2026-08-01T00:00:00+00:00', $body['state']['hiddenAt']);

        $client->request('GET', '/api/entries', server: $headers);
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['entries']);
        foreach ($list['entries'] as $entry) {
            self::assertIsArray($entry);
            self::assertTrue($entry['isHidden'], 'Every swept entry must stay read.');
        }

        self::assertSame(0, $this->unreadCountOf($client, $headers, $subscription->requireId()));
    }

    public function testMarkingViewedReadsTheEntryEvenAboveTheWatermark(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-viewed-boundary@example.com');
        $subscription = $this->seedFeedWithEntries($user, 3);

        // Sweep to exactly the second entry's date: entry 2 is read (the
        // watermark is inclusive), entry 3 is not.
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-07-02T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $onTheWatermark = $this->entryIdOf($subscription, 'g2');
        $aboveTheWatermark = $this->entryIdOf($subscription, 'g3');

        self::assertTrue($this->markViewed($client, $headers, $onTheWatermark)['isHidden']);

        // Above the watermark the sweep left it unread, but viewing reads it now
        // (#482 subset invariant): viewed can never be true while read is false.
        $above = $this->markViewed($client, $headers, $aboveTheWatermark);
        self::assertTrue($above['isHidden']);
        self::assertNotNull($above['hiddenAt']);
    }

    private function entryIdOf(Subscription $subscription, string $guid): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy([
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
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
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
            content: '{"isHidden":true,"isFavorite":true}',
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isViewed']);
        self::assertNotNull($body['state']['viewedAt']);
    }

    /**
     * The duplicate-collapse badge count (#496) hides a higher-id duplicate's
     * own unread count as long as a lower-id copy is unread: two subscribed
     * copies of the same article show ONE unread, attributed to the survivor
     * (lowest id). Without the mirror, hiding the survivor alone would make the
     * sibling's own copy the "only" unread one left in its group — its badge
     * would jump from 0 to 1, a duplicate resurfacing as unread in another
     * feed. Mirroring isHidden onto the sibling keeps its badge at 0.
     */
    public function testHidingASurvivorMirrorsToTheSiblingSoItsBadgeStaysClear(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-mirror@example.com');

        $earlier = new \DateTimeImmutable('2026-07-05T09:00:00Z');
        $later = new \DateTimeImmutable('2026-07-05T10:00:00Z');
        $survivor = $this->seedFeedWithMatchingEntry($user, 'Feed A', 'urlhash-mirror-x', $earlier);
        $sibling = $this->seedFeedWithMatchingEntry($user, 'Feed B', 'urlhash-mirror-x', $later);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $survivorSubscription = $entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $survivor->getFeed()]);
        $siblingSubscription = $entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $sibling->getFeed()]);
        self::assertInstanceOf(Subscription::class, $survivorSubscription);
        self::assertInstanceOf(Subscription::class, $siblingSubscription);

        self::assertSame(1, $this->unreadCountOf($client, $headers, $survivorSubscription->requireId()));
        self::assertSame(0, $this->unreadCountOf($client, $headers, $siblingSubscription->requireId()));

        $client->request(
            'PATCH',
            '/api/entries/' . $survivor->getId() . '/state',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->unreadCountOf($client, $headers, $survivorSubscription->requireId()));
        self::assertSame(
            0,
            $this->unreadCountOf($client, $headers, $siblingSubscription->requireId()),
            'Hiding the survivor must mirror isHidden onto the sibling copy, not resurrect it as unread.',
        );
    }

    public function testCannotPatchEntryOfUnsubscribedFeed(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-idor@example.com');
        [, $stranger] = $this->auth('e-owner@example.com');
        $strangerSubscription = $this->seedFeedWithEntries($stranger, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $strangerSubscription->getFeed()])
            ?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testGetReturnsOwnedEntry(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-get@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $subscription->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);
        self::assertSame($entryId, $body['entry']['id']);
        self::assertSame('Post 1', $body['entry']['title']);
        self::assertSame('Seeded', $body['entry']['source']);
        self::assertFalse($body['entry']['isHidden']);
    }

    public function testGetReturnsSavedSearchMembership(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-get-saved-search@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);
        $search = $this->seedSavedSearchMembership($user, 'post', $entry);

        $client->request('GET', "/api/entries/{$entry->getId()}", server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);
        self::assertSame(
            [['id' => $search->getId(), 'slug' => $search->getSlug(), 'term' => $search->getTerm()]],
            $body['entry']['savedSearches'],
        );
    }

    public function testGetReturnsContentHtmlOnTheDetailShape(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-get-detail@example.com');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/detail-feed.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $july1 = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, 'detail-1', 'https://example.com/1', 'Post', $july1, $july1);
        $entry->setContentHtml('<p>The full body of the article.</p>');
        $entityManager->persist($entry);
        $entityManager->flush();
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);
        self::assertSame('<p>The full body of the article.</p>', $body['entry']['contentHtml']);
        self::assertSame('The full body of the article.', $body['entry']['excerpt']);
    }

    public function testGetReturnsTheDiscussionUrlAndCommentsLoad(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-get-discussion@example.com');
        $entryId = $this->seedEntryWithDiscussion(
            $user,
            Discussion::withCommentsFeed('https://t.example/1', 'https://t.example/1/.rss', CommentsLoad::Auto),
        );

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);
        self::assertSame('https://t.example/1', $body['entry']['discussionUrl']);
        self::assertSame('auto', $body['entry']['comments']);
    }

    public function testGetReturnsNullDiscussionFieldsWhenAbsent(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-get-no-discussion@example.com');
        $entryId = $this->seedEntryWithDiscussion($user, Discussion::none());

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);
        self::assertArrayHasKey('discussionUrl', $body['entry']);
        self::assertNull($body['entry']['discussionUrl']);
        self::assertArrayHasKey('comments', $body['entry']);
        self::assertNull($body['entry']['comments']);
    }

    public function testGetUnsubscribedEntryIs404(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-get-idor@example.com');
        [, $stranger] = $this->auth('e-get-owner@example.com');
        $strangerSubscription = $this->seedFeedWithEntries($stranger, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entryId = $entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['feed' => $strangerSubscription->getFeed()])
            ?->getId();
        self::assertNotNull($entryId);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such entry."', (string) $client->getResponse()->getContent());
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

    /**
     * @param array<string, string>       $payload
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('invalidMarkReadPayloads')]
    public function testMarkReadValidationErrorsKeepTheirKeysAndMessages(array $payload, array $errors): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markinvalid@example.com');
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload + ['until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertSame($errors, $body['errors']);
    }

    /** @return iterable<string, array{array<string, string>, array<string, list<string>>}> */
    public static function invalidMarkReadPayloads(): iterable
    {
        yield 'feed without an id' => [
            ['scope' => 'feed'],
            ['id' => ['An id is required when scope is "feed".']],
        ];
        yield 'tag without an id' => [
            ['scope' => 'tag'],
            ['id' => ['An id is required when scope is "tag".']],
        ];
        yield 'unknown scope' => [
            ['scope' => 'bogus'],
            ['scope' => ['The value you selected is not a valid choice.']],
        ];
    }

    public function testMarkReadBatchMarksOnlyTheGivenEntries(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markbatch@example.com');
        $subscription = $this->seedFeedWithEntries($user, 3);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entries = $entityManager
            ->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);
        $markIds = [$entries[0]->requireId(), $entries[1]->requireId()];

        $this->postMarkReadBatch($client, $headers, $markIds);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(1, $this->entriesOf($client), 'The unmarked entry stays unread.');
    }

    public function testMarkReadBatchToleratesDuplicateIds(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markbatch-dup@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);
        $id = $entry->requireId();

        $this->postMarkReadBatch($client, $headers, [$id, $id]);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(0, $this->entriesOf($client), 'The duplicated id is marked read exactly once.');
    }

    public function testMarkReadBatchIgnoresNonexistentIds(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markbatch-missing@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);
        $id = $entry->requireId();
        $missingId = 99999999;
        self::assertNull($entityManager->getRepository(Entry::class)->find($missingId));

        $this->postMarkReadBatch($client, $headers, [$id, $missingId]);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(0, $this->entriesOf($client), 'The existing id is marked read; the missing one is ignored.');

        // No state row at all: `findExistingIds()` dropped the id, not a dialect's FK check.
        $states = self::getContainer()->get(EntryStateRepository::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);
        $userId = $user->requireId();
        self::assertNull($states->findOneForUserEntry($userId, $missingId));
        $existingState = $states->findOneForUserEntry($userId, $id);
        self::assertInstanceOf(EntryState::class, $existingState);
        self::assertTrue($existingState->isHidden());
    }

    public function testMarkReadBatchRejectsEmptyIds(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbatch-empty@example.com');

        $this->postMarkReadBatch($client, $headers, []);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
    }

    public function testMarkReadBatchRejectsNonPositiveIds(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbatch-neg@example.com');

        $this->postMarkReadBatch($client, $headers, [0, -5]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadBatchRejectsOverTheCap(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbatch-cap@example.com');

        $this->postMarkReadBatch($client, $headers, range(1, MarkEntriesReadRequest::MAX_IDS + 1));
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadBatchRejectsAnonymous(): void
    {
        $client = self::createClient();

        $this->postMarkReadBatch($client, [], [1]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $headers
     * @param list<int> $ids
     */
    private function postMarkReadBatch(KernelBrowser $client, array $headers, array $ids): void
    {
        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $ids], \JSON_THROW_ON_ERROR),
        );
    }
}
