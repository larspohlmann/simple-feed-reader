<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\EntryList;

use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\Feed;
use App\Entity\PostCommunity;
use App\Service\Library\EntryList\ConnectedAccountStates;
use App\Service\Social\Lemmy\Support\LemmyProfileFeed;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\SeedsUsers;

final class ConnectedAccountStatesTest extends DbTestCase
{
    use SeedsUsers;

    public function testAPostInItsOwnCommunityFeedCarriesNoCommunity(): void
    {
        $entry = $this->lemmyPostIn(LemmyProfileFeed::buildUrl('technology', 'lemmy.world'));

        self::assertNull($this->findCommunity($entry));
    }

    public function testTheOwnCommunityFeedMatchesWhateverCaseTheFeedUrlHas(): void
    {
        $entry = $this->lemmyPostIn(LemmyProfileFeed::buildUrl('Technology', 'lemmy.world'));

        self::assertNull($this->findCommunity($entry));
    }

    public function testAPostInAnotherFeedCarriesItsCommunity(): void
    {
        $entry = $this->lemmyPostIn('https://example.com/feed.xml');

        self::assertSame('technology@lemmy.world', $this->findCommunity($entry)?->identity);
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

    private function lemmyPostIn(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $entry = LemmyPostEntries::on($feed);
        $this->entityManager->persist($entry);
        $post = new EntryLemmyPost($entry);
        $post->placeIn(PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'));
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return $entry;
    }
}
