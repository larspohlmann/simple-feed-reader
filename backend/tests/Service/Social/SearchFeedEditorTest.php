<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryState;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Exception\NotASearchFeedException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\SearchFeedEditor;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SearchFeedEditorTest extends DbTestCase
{
    private const string NOW = '2026-11-07 12:00:00';

    private MockClock $clock;
    private User $user;
    private RecordingSearchIndexWriter $indexWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(self::NOW, 'UTC');
        $this->indexWriter = new RecordingSearchIndexWriter();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('search-editor@example.test');
    }

    public function testANewQueryRetargetsTheFeedInPlace(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss reader');
        $socialFeed = $this->socialFeedOf($subscription);
        $socialFeed->recordNewestSeen('9000');
        $this->entityManager->flush();

        $this->editor()->requery($subscription, "  feed \t reader ");

        $feed = $subscription->getFeed();
        self::assertSame(sprintf('social:bluesky:%d:search:feed reader', $account->requireId()), $feed->getUrl());
        self::assertSame('https://bsky.app/search?q=feed%20reader', $feed->getSiteUrl());
        self::assertSame('feed reader', $feed->getTitle());
        self::assertSame('feed reader', $socialFeed->getRemoteId());
        self::assertNull($socialFeed->getNewestSeenId());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $feed->getNextFetchAt());
        $this->entityManager->clear();
        $stored = $this->entityManager->find(SocialFeed::class, $socialFeed->requireId());
        self::assertSame('feed reader', $stored?->getRemoteId());
    }

    public function testACustomTitleAndARenamedFeedTitleStay(): void
    {
        $account = $this->stored(SocialAccounts::mastodonOf($this->user));
        $subscription = $this->searchFor($account, 'rss reader');
        $subscription->setCustomTitle('My readers');
        $subscription->getFeed()->setTitle('Readers');
        $this->entityManager->flush();

        $this->editor()->requery($subscription, 'feed reader');

        self::assertSame('My readers', $subscription->getCustomTitle());
        self::assertSame('Readers', $subscription->getFeed()->getTitle());
        self::assertSame('https://mastodon.example/search?q=feed%20reader', $subscription->getFeed()->getSiteUrl());
    }

    public function testTheSameQueryChangesNothing(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss reader');
        $this->post($subscription, 'other', '<p>atom only</p>', null);

        $this->editor()->requery($subscription, ' rss  reader ');

        self::assertNull($subscription->getFeed()->getNextFetchAt());
        self::assertSame(['other'], $this->remainingGuids($subscription));
    }

    public function testAQueryTheAccountAlreadySearchesForIsRefused(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss reader');
        $this->searchFor($account, 'feed reader');

        $this->expectException(AlreadySubscribedException::class);

        $this->editor()->requery($subscription, 'feed reader');
    }

    public function testAFeedThatIsNoSearchIsRefused(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $privateFeeds = PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock);
        $home = $privateFeeds->subscribe($account, PrivateFeedModel::home(), []);

        $this->expectException(NotASearchFeedException::class);

        $this->editor()->requery($home, 'rss');
    }

    public function testStoredPostsNoLongerMatchingAreRemovedButFavouritesAndSavedOnesStay(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss');
        $this->post($subscription, 'matching', '<p>A <a href="https://example.test/">rss reader</a></p>', null);
        $gone = $this->post($subscription, 'gone', '<p>An rss gazette</p>', null)->requireId();
        $alsoGone = $this->post($subscription, 'also-gone', '<p>rss and atom</p>', null)->requireId();
        $favourite = $this->state($this->post($subscription, 'favourite', '<p>rss gazette</p>', null));
        $favourite->markFavorite();
        $kept = $this->state($this->post($subscription, 'kept', '<p>rss gazette</p>', null));
        $kept->markKept();
        $this->entityManager->flush();

        $this->editor()->requery($subscription, '"rss reader" -gazette');

        self::assertEqualsCanonicalizing(['favourite', 'kept', 'matching'], $this->remainingGuids($subscription));
        self::assertCount(1, $this->indexWriter->forgets);
        self::assertEqualsCanonicalizing([$gone, $alsoGone], $this->indexWriter->forgets[0]);
    }

    public function testAFeedFullOfNonMatchingPostsIsRemovedInChunks(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss');
        $fetchedAt = new \DateTimeImmutable(self::NOW);
        for ($index = 0; $index < 501; ++$index) {
            $entry = new Entry($subscription->getFeed(), 'atom-' . $index, null, 'atom', $fetchedAt, $fetchedAt);
            $entry->setContentHtml('<p>atom</p>');
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();

        $this->editor()->requery($subscription, 'rss reader');

        self::assertSame([500, 1], array_map(\count(...), $this->indexWriter->forgets));
        self::assertSame([], $this->remainingGuids($subscription));
    }

    public function testTheAuthorIsCheckedAgainstTheStoredBlueskyProfile(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss');
        $this->post($subscription, 'amy', '<p>rss</p>', 'https://bsky.app/profile/amy.bsky.social');
        $this->post($subscription, 'ben', '<p>rss</p>', 'https://bsky.app/profile/ben.bsky.social');

        $this->editor()->requery($subscription, 'rss -from:@Amy.bsky.social');

        self::assertSame(['ben'], $this->remainingGuids($subscription));
    }

    public function testABlueskyAuthorIsAlsoKnownByTheDidInThePostsAddress(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $subscription = $this->searchFor($account, 'rss');
        $amy = 'at://did:plc:amy/app.bsky.feed.post/1';
        $this->post($subscription, $amy, '<p>rss</p>', 'https://bsky.app/profile/amy-renamed.bsky.social');
        $ben = 'at://did:plc:ben/app.bsky.feed.post/2';
        $this->post($subscription, $ben, '<p>rss</p>', 'https://bsky.app/profile/ben.bsky.social');

        $this->editor()->requery($subscription, 'rss from:did:plc:amy');

        self::assertSame([$amy], $this->remainingGuids($subscription));
    }

    public function testAMastodonAuthorMatchesInFullAndALocalOneByUserNameAlone(): void
    {
        $account = $this->stored(SocialAccounts::mastodonOf($this->user));
        $subscription = $this->searchFor($account, 'rss');
        $this->post($subscription, 'local', '<p>rss</p>', 'https://mastodon.example/@amy');
        $this->post($subscription, 'remote', '<p>rss</p>', 'https://other.example/@amy');
        $this->post($subscription, 'remote-ben', '<p>rss</p>', 'https://other.example/users/ben');
        $this->post($subscription, 'local-cy', '<p>rss</p>', 'https://mastodon.example/@cy');

        $this->editor()->requery($subscription, 'rss -from:amy -from:ben@other.example -from:cy@mastodon.example');

        self::assertSame(['remote'], $this->remainingGuids($subscription));
    }

    private function editor(): SearchFeedEditor
    {
        return new SearchFeedEditor(
            SocialFeeds::repository($this->entityManager),
            new RetentionRepository($this->entityManager, new RowIds($this->entityManager)),
            EntryIndexers::with($this->indexWriter),
            $this->entityManager,
            new NaiveUtcClock($this->clock),
        );
    }

    private function stored(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function searchFor(SocialAccount $account, string $query): Subscription
    {
        $privateFeeds = PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock);

        return $privateFeeds->subscribe($account, PrivateFeedModel::search($query), []);
    }

    private function socialFeedOf(Subscription $subscription): SocialFeed
    {
        $socialFeed = SocialFeeds::repository($this->entityManager)->forFeed($subscription->getFeed());
        self::assertNotNull($socialFeed);

        return $socialFeed;
    }

    private function post(Subscription $subscription, string $guid, string $contentHtml, ?string $authorUrl): Entry
    {
        $fetchedAt = new \DateTimeImmutable(self::NOW);
        $entry = new Entry($subscription->getFeed(), $guid, null, $guid, $fetchedAt, $fetchedAt);
        $entry->setContentHtml($contentHtml);
        $entry->setAuthor(new EntryAuthor('Someone', $authorUrl));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function state(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    /** @return list<string> */
    private function remainingGuids(Subscription $subscription): array
    {
        $this->entityManager->clear();
        $entries = $this->entityManager->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()->requireId()]);

        return array_map(static fn (Entry $entry): string => $entry->getGuid(), $entries);
    }
}
