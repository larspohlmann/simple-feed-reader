<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Factory\ThreadPostFactory;
use App\Service\Social\Model\ThreadPostModel;
use App\Service\Social\SelfThreadFolder;
use App\Tests\DbTestCase;

final class SelfThreadFolderTest extends DbTestCase
{
    public function testEachReplyBecomesAThreadPostWithItsCard(): void
    {
        $entry = $this->entry();
        $cardUrl = 'https://www.macrumors.com/2026/10/16/app-store-rules-deepfakes/';
        $this->folder()->fold($entry, [
            self::reply('<p>The rule extends…</p>', null),
            self::reply('', new LinkCardContent($cardUrl, 'New App Store Rules', null, null, 'www.macrumors.com')),
        ]);
        $this->entityManager->flush();

        $posts = $this->threadPosts()->byEntryIds([$entry->requireId()])[$entry->requireId()];
        self::assertSame(
            ['<p>The rule extends…</p>', ''],
            array_map(static fn (ThreadPostModel $post): string => $post->html, $posts),
        );
        self::assertSame($cardUrl, $posts[1]->linkCard?->content->url);
        self::assertSame(ShareKind::Link, $entry->getShareKind());
    }

    public function testAnEntryThatSharesSomethingKeepsItsKind(): void
    {
        $entry = $this->entry();
        $entry->setShareKind(ShareKind::Media);
        $card = new LinkCardContent('https://a.example/', 'A', null, null, 'a.example');

        $this->folder()->fold($entry, [self::reply('', $card)]);

        self::assertSame(ShareKind::Media, $entry->getShareKind());
    }

    public function testATextOnlyChainLeavesTheKindAlone(): void
    {
        $entry = $this->entry();

        $this->folder()->fold($entry, [self::reply('<p>more</p>', null)]);

        self::assertNull($entry->getShareKind());
    }

    public function testThePublishedTimeIsStoredInUtc(): void
    {
        $entry = $this->entry();
        $publishedAt = new \DateTimeImmutable('2026-10-17T10:15:31+02:00');

        $this->folder()->fold($entry, [self::reply('<p>x</p>', null, $publishedAt)]);
        $this->entityManager->flush();

        $posts = $this->threadPosts()->byEntryIds([$entry->requireId()])[$entry->requireId()];
        self::assertSame('2026-10-17 08:15:31', $posts[0]->publishedAt?->format('Y-m-d H:i:s'));
    }

    private static function reply(
        string $html,
        ?LinkCardContent $card,
        ?\DateTimeImmutable $at = null,
    ): EntryCommentModel {
        return new EntryCommentModel(
            new CommentAuthorModel('A', null, null, null),
            'https://bsky.app/profile/a/post/' . md5($html),
            $at,
            $html,
            true,
            new CommentEmbedsModel([], $card),
        );
    }

    private function folder(): SelfThreadFolder
    {
        $linkCards = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $linkCards);

        return new SelfThreadFolder($this->entityManager, new ThreadPostFactory(), $linkCards);
    }

    private function threadPosts(): ThreadPostRepository
    {
        $repository = self::getContainer()->get(ThreadPostRepository::class);
        self::assertInstanceOf(ThreadPostRepository::class, $repository);

        return $repository;
    }

    private function entry(): Entry
    {
        $feed = new Feed('https://example.com/fold/rss');
        $entry = new Entry(
            $feed,
            'fold-guid',
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
}
