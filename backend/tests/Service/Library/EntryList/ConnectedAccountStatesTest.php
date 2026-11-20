<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\EntryList;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PostCommunity;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\VoteScore;
use App\Service\Library\EntryList\ConnectedAccountStates;
use App\Service\Library\EntryList\Model\ConnectedAccountStatesModel;
use App\Service\Social\Lemmy\Support\LemmyProfileFeed;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class ConnectedAccountStatesTest extends DbTestCase
{
    use SeedsUsers;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = $this->user('states@example.com');
    }

    public function testAPostInItsOwnCommunityFeedCarriesNoCommunity(): void
    {
        $entry = $this->persistLemmyPost(LemmyProfileFeed::buildUrl('technology', 'lemmy.world'));

        self::assertNull($this->findCommunity($entry));
    }

    public function testTheOwnCommunityFeedMatchesWhateverCaseTheFeedUrlHas(): void
    {
        $entry = $this->persistLemmyPost(LemmyProfileFeed::buildUrl('Technology', 'lemmy.world'));

        self::assertNull($this->findCommunity($entry));
    }

    public function testAPostInAnotherFeedCarriesItsCommunity(): void
    {
        $entry = $this->persistLemmyPost('https://example.com/feed.xml');

        self::assertSame('technology@lemmy.world', $this->findCommunity($entry)?->identity);
    }

    public function testBothCopiesShowTheItemScore(): void
    {
        $first = $this->persistLemmyPost('https://example.com/feed.xml');
        $item = $first->getCanonicalItem();
        self::assertNotNull($item);
        $item->recordScore(12);
        $second = $this->persistLemmyCopy($item);

        $states = $this->buildStates([$first, $second]);

        foreach ([$first, $second] as $entry) {
            $lemmy = $states->lemmy[$entry->requireId()] ?? null;
            self::assertNotNull($lemmy);
            self::assertSame(12, $lemmy->score);
            self::assertSame('technology@lemmy.world', $lemmy->community?->identity);
        }
    }

    public function testAVoteOnOneCopyShowsOnTheOther(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::lemmyOf($this->reader));
        $first = $this->persistLemmyPost('https://example.com/feed.xml');
        $item = $first->getCanonicalItem();
        self::assertNotNull($item);
        $second = $this->persistLemmyCopy($item);
        $votedAt = new \DateTimeImmutable('2026-11-20 10:00:00');
        $this->entityManager->persist(new DiscussionVote($this->reader, $item, $account, VoteScore::Up, $votedAt));
        $this->entityManager->flush();

        $states = $this->buildStates([$first, $second]);

        foreach ([$first, $second] as $entry) {
            self::assertSame(VoteScore::Up, ($states->lemmy[$entry->requireId()] ?? null)?->myVote);
        }
    }

    public function testALikeOnOneCopyShowsOnTheOther(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->reader));
        $item = CanonicalItems::buildItem(
            CanonicalItemKind::Mastodon,
            'https://mastodon.example/users/alice/statuses/1',
        );
        $this->entityManager->persist($item);
        $first = $this->persistMastodonCopy('https://mastodon.example/@alice.rss', $item);
        $second = $this->persistMastodonCopy('https://mastodon.social/@alice.rss', $item);
        $likedAt = new \DateTimeImmutable('2026-11-20 10:00:00');
        $this->entityManager->persist(new SocialLike($this->reader, $item, $account, '1', $likedAt));
        $this->entityManager->flush();

        $states = $this->buildStates([$first, $second]);

        foreach ([$first, $second] as $entry) {
            self::assertTrue(($states->social[$entry->requireId()] ?? null)?->isLiked);
        }
    }

    public function testAnItemOfAnotherKindShowsNoLemmyScore(): void
    {
        $entry = LemmyPostEntries::buildEntry($this->persistFeed('https://example.com/feed.xml'));
        $item = CanonicalItems::buildItem(CanonicalItemKind::Bluesky, 'at://did:plc:x/app.bsky.feed.post/1');
        $item->recordScore(5);
        $entry->linkTo($item);
        $this->entityManager->persist($item);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        self::assertArrayNotHasKey($entry->requireId(), $this->buildStates([$entry])->lemmy);
    }

    /**
     * @param list<Entry> $entries
     */
    private function buildStates(array $entries): ConnectedAccountStatesModel
    {
        $states = self::getContainer()->get(ConnectedAccountStates::class);
        self::assertInstanceOf(ConnectedAccountStates::class, $states);
        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry->requireId()] = $entry;
        }

        return $states->buildStates($byId, $this->reader->requireId());
    }

    private function persistFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function findCommunity(Entry $entry): ?PostCommunity
    {
        $states = self::getContainer()->get(ConnectedAccountStates::class);
        self::assertInstanceOf(ConnectedAccountStates::class, $states);

        $entryId = $entry->requireId();
        $lemmy = $states->buildStates([$entryId => $entry], $this->reader->requireId())->lemmy[$entryId] ?? null;
        self::assertNotNull($lemmy);

        return $lemmy->community;
    }

    private function persistLemmyCopy(CanonicalItem $item): Entry
    {
        $otherFeed = $this->persistFeed('https://example.org/other.xml');
        $copy = LemmyPostEntries::buildEntry($otherFeed, LemmyPostEntries::PAGE, 'copy-b');
        $copy->linkTo($item);
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    private function persistMastodonCopy(string $feedUrl, CanonicalItem $item): Entry
    {
        $feed = $this->persistFeed($feedUrl);
        $published = new \DateTimeImmutable('2026-11-20 09:00:00');
        $statusUrl = 'https://mastodon.example/@alice/1';
        $copy = new Entry($feed, $statusUrl, $statusUrl, 'A status', $published, $published);
        $copy->linkTo($item);
        $this->entityManager->persist($copy);

        return $copy;
    }

    private function persistLemmyPost(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $entry = LemmyPostEntries::buildEntry($feed);
        $this->entityManager->persist($entry);
        $item = LemmyPostEntries::linkItem($entry);
        $this->entityManager->persist($item);
        LemmyPostEntries::storeCommunity(
            $this->entityManager,
            $item,
            PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'),
        );

        return $entry;
    }
}
