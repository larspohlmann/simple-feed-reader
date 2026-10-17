<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\EntryTwinRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;

final class EntrySameGuidTest extends DbTestCase
{
    use SeedsUsers;

    private User $user;
    private Feed $source;
    private Feed $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user('entry-same-guid@example.test');
        $this->source = $this->feed('https://bsky.app/profile/did:plc:rusty/rss');
        $this->target = $this->feed('social:bluesky:1:author:did:plc:rusty');
    }

    public function testEachPostTheUserKeptSomethingOnIsPairedWithItsTwinInTheOtherFeed(): void
    {
        $read = $this->entry($this->source, 'post-read');
        $readTwin = $this->entry($this->target, 'post-read');
        $liked = $this->entry($this->source, 'post-liked');
        $likedTwin = $this->entry($this->target, 'post-liked');
        $this->entry($this->source, 'post-untouched');
        $this->entry($this->target, 'post-untouched');
        $onlyInRss = $this->entry($this->source, 'post-only-in-rss');
        $strangersPost = $this->entry($this->source, 'post-of-a-stranger');
        $this->entry($this->target, 'post-of-a-stranger');
        $this->entityManager->persist(new EntryState($this->user, $read));
        $this->entityManager->persist(new EntryState($this->user, $onlyInRss));
        $stranger = $this->user('entry-same-guid-other@example.test');
        $this->entityManager->persist(new EntryState($stranger, $strangersPost));
        $account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($account);
        $this->entityManager->persist(
            new SocialLike($this->user, $liked, $account, 'at://like', new \DateTimeImmutable()),
        );
        $this->entityManager->flush();

        $pairs = $this->entryTwins()->sameGuidEntryIds(
            $this->user->requireId(),
            $this->source->requireId(),
            $this->target->requireId(),
        );
        ksort($pairs);

        $expected = [$read->requireId() => $readTwin->requireId(), $liked->requireId() => $likedTwin->requireId()];
        ksort($expected);
        self::assertSame($expected, $pairs);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $at = new \DateTimeImmutable('2026-10-16 07:00:00');
        $entry = new Entry($feed, $guid, null, $guid, $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function entryTwins(): EntryTwinRepository
    {
        $entryTwins = self::getContainer()->get(EntryTwinRepository::class);
        self::assertInstanceOf(EntryTwinRepository::class, $entryTwins);

        return $entryTwins;
    }
}
