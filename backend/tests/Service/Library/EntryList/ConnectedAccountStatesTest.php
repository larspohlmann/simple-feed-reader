<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\EntryList;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Enum\CanonicalItemKind;
use App\Service\Library\EntryList\ConnectedAccountStates;
use App\Service\Library\EntryList\Model\ConnectedAccountStatesModel;
use App\Service\Social\Lemmy\Support\LemmyProfileFeed;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\SeedsUsers;

final class ConnectedAccountStatesTest extends DbTestCase
{
    use SeedsUsers;

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
        $otherFeed = $this->persistFeed('https://example.org/other.xml');
        $second = LemmyPostEntries::buildEntry($otherFeed, LemmyPostEntries::PAGE, 'copy-b');
        $second->linkTo($item);
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        $states = $this->buildStates([$first, $second]);

        foreach ([$first, $second] as $entry) {
            $lemmy = $states->lemmy[$entry->requireId()] ?? null;
            self::assertNotNull($lemmy);
            self::assertSame(12, $lemmy->score);
            self::assertSame('technology@lemmy.world', $lemmy->community?->identity);
        }
    }

    public function testAnItemOfAnotherKindShowsNoLemmyScore(): void
    {
        $entry = LemmyPostEntries::buildEntry($this->persistFeed('https://example.com/feed.xml'));
        $item = new CanonicalItem(CanonicalItemKind::Bluesky, 'at://did:plc:x/app.bsky.feed.post/1');
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

        return $states->buildStates($byId, $this->user('states@example.com')->requireId());
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
        $user = $this->user('states@example.com');

        $entryId = $entry->requireId();
        $lemmy = $states->buildStates([$entryId => $entry], $user->requireId())->lemmy[$entryId] ?? null;
        self::assertNotNull($lemmy);

        return $lemmy->community;
    }

    private function persistLemmyPost(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $entry = LemmyPostEntries::buildEntry($feed);
        $this->entityManager->persist($entry);
        $item = LemmyPostEntries::linkItem($entry);
        $this->entityManager->persist($item);
        $post = new LemmyPost($item);
        $post->placeIn(PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'));
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return $entry;
    }
}
