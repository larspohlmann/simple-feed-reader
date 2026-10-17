<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Repository\LinkCardRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Social\Model\ThreadPostModel;
use App\Tests\DbTestCase;

final class ThreadPostRepositoryTest extends DbTestCase
{
    public function testThreadPostsComeInPositionOrderWithTheirCard(): void
    {
        $entry = $this->entry();
        $second = new ThreadPost($entry, 2, new ThreadPostContent('https://x/2', null, '<p>two</p>', []));
        $first = new ThreadPost(
            $entry,
            1,
            new ThreadPostContent('https://x/1', new \DateTimeImmutable('2026-10-17 08:15:31'), '<p>one</p>', []),
        );
        $this->entityManager->persist($second);
        $this->entityManager->persist($first);
        $this->linkCards()->appendToThreadPost(
            $second,
            new LinkCardContent('https://macrumors.example/a', 'New rules', null, null, 'macrumors.example'),
        );
        $this->entityManager->flush();

        $posts = $this->threadPosts()->byEntryIds([$entry->requireId()])[$entry->requireId()];

        self::assertSame(
            ['https://x/1', 'https://x/2'],
            array_map(static fn (ThreadPostModel $post): ?string => $post->url, $posts),
        );
        $replyCard = $posts[1]->linkCard;
        self::assertNull($posts[0]->linkCard);
        self::assertSame(0, $replyCard?->position);
        self::assertSame('https://macrumors.example/a', $replyCard->content->url);
        self::assertSame(['https://x/1', 'https://x/2'], $this->threadPosts()->urlsOf($entry));
    }

    public function testAnEntryWithoutThreadIsAbsent(): void
    {
        $entry = $this->entry();
        $this->entityManager->flush();

        self::assertSame([], $this->threadPosts()->byEntryIds([$entry->requireId()]));
        self::assertSame([], $this->threadPosts()->byEntryIds([]));
    }

    private function entry(): Entry
    {
        $feed = new Feed('https://example.com/thread/rss');
        $entry = new Entry(
            $feed,
            'thread-guid',
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 08:00:00'),
            new \DateTimeImmutable('2026-10-10 08:00:00'),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function linkCards(): LinkCardRepository
    {
        $repository = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $repository);

        return $repository;
    }

    private function threadPosts(): ThreadPostRepository
    {
        $repository = self::getContainer()->get(ThreadPostRepository::class);
        self::assertInstanceOf(ThreadPostRepository::class, $repository);

        return $repository;
    }
}
