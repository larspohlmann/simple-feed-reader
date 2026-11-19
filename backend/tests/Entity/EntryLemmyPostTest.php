<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\Feed;
use App\Entity\PostCommunity;
use PHPUnit\Framework\TestCase;

final class EntryLemmyPostTest extends TestCase
{
    public function testANewPostHasNeitherScoreNorCommunity(): void
    {
        $post = new EntryLemmyPost($this->entry());

        self::assertNull($post->getScore());
        self::assertNull($post->getCommunity());
    }

    public function testARecordedScoreIsStored(): void
    {
        $post = new EntryLemmyPost($this->entry());

        $post->recordScore(12);

        self::assertSame(12, $post->getScore());
    }

    public function testANullScoreKeepsTheStoredOne(): void
    {
        $post = new EntryLemmyPost($this->entry());
        $post->recordScore(12);

        $post->recordScore(null);

        self::assertSame(12, $post->getScore());
    }

    public function testAPostInACommunityNamesIt(): void
    {
        $post = new EntryLemmyPost($this->entry());

        $post->placeIn(PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'));

        self::assertEquals(
            PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'),
            $post->getCommunity(),
        );
    }

    public function testThePostKeepsItsEntry(): void
    {
        $entry = $this->entry();

        self::assertSame($entry, (new EntryLemmyPost($entry))->getEntry());
    }

    private function entry(): Entry
    {
        return new Entry(
            new Feed('https://example.test/feed.xml'),
            'guid',
            'https://example.test/1',
            'Title',
            new \DateTimeImmutable('2026-11-18 06:00:00'),
            new \DateTimeImmutable('2026-11-18 05:00:00'),
        );
    }
}
