<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Repository\LinkCardRepository;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;

final class LinkCardRepositoryTest extends DbTestCase
{
    public function testAppendingKeepsTheGivenOrder(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();

        $cards->append($entry, [$this->link('b'), $this->link('a'), $this->link('c')]);
        $this->entityManager->flush();

        $stored = $cards->contentsByEntryIds([$entry->requireId()]);
        self::assertSame(['b', 'a', 'c'], self::titlesOf($stored[$entry->requireId()]));
    }

    public function testStoresTheOptionalFieldsAsGiven(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $full = new LinkCardContent('https://example.com/a', 'Title', 'About', 'https://example.com/a.jpg', 'Example');
        $bare = new LinkCardContent('https://example.com/b', 'Bare', null, null, 'example.com');

        $cards->append($entry, [$this->linkOf($full), $this->linkOf($bare)]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertEquals([$full, $bare], $cards->contentsByEntryIds([$entry->requireId()])[$entry->requireId()]);
    }

    public function testTwoEntriesSharingAUrlReadTheFirstPreview(): void
    {
        $first = $this->entry('first');
        $second = $this->entry('second');
        $cards = $this->repository();
        $preview = new LinkCardContent('https://example.com/a', 'First', 'About', null, 'Example');
        $cards->append($first, [$this->linkOf($preview)]);
        $cards->append($second, [
            $this->linkOf(new LinkCardContent('https://example.com/a?utm_source=x', 'Second', null, null, 'Other')),
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $cards->contentsByEntryIds([$first->requireId(), $second->requireId()]);

        self::assertEquals([$preview], $stored[$first->requireId()]);
        self::assertEquals([$preview], $stored[$second->requireId()]);
    }

    public function testCardsGoWithTheirEntry(): void
    {
        $entry = $this->entry('gone');
        $this->repository()->append($entry, [$this->link('a')]);
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM entry WHERE id = ?', [$entry->requireId()]);

        self::assertSame([], $connection->fetchFirstColumn('SELECT id FROM link_card'));
    }

    public function testGroupsTheCardsByEntryInPositionOrder(): void
    {
        $first = $this->entry('first');
        $second = $this->entry('second');
        $empty = $this->entry('empty');
        $cards = $this->repository();
        $cards->append($second, [$this->link('s1')]);
        $cards->append($first, [$this->link('f1'), $this->link('f2')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $grouped = $cards->contentsByEntryIds([$first->requireId(), $second->requireId(), $empty->requireId()]);

        self::assertEqualsCanonicalizing([$first->requireId(), $second->requireId()], array_keys($grouped));
        self::assertSame(['f1', 'f2'], self::titlesOf($grouped[$first->requireId()]));
        self::assertSame(['s1'], self::titlesOf($grouped[$second->requireId()]));
    }

    public function testFindingForNoEntriesIsEmpty(): void
    {
        self::assertSame([], $this->repository()->contentsByEntryIds([]));
    }

    public function testLaterCardsFollowTheStoredOnes(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->append($entry, [$this->link('a')]);
        $this->entityManager->flush();

        $cards->append($entry, [$this->link('b'), $this->link('c')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $cards->contentsByEntryIds([$entry->requireId()]);
        self::assertSame(['a', 'b', 'c'], self::titlesOf($stored[$entry->requireId()]));
    }

    public function testAThreadCardTakesTheNextPositionAndStaysOutOfTheRootCards(): void
    {
        $cards = $this->repository();
        $entry = $this->entry('one');
        $cards->append($entry, [$this->link('root')]);
        $post = new ThreadPost(
            $entry,
            1,
            new ThreadPostContent('https://bsky.app/profile/a/post/r1', null, '<p>r</p>', []),
        );
        $this->entityManager->persist($post);
        $cards->appendToThreadPost($post, $this->link('reply'));
        $this->entityManager->flush();

        self::assertSame(['https://example.com/root'], array_map(
            static fn (LinkCardContent $card): string => $card->url,
            $cards->contentsByEntryIds([$entry->requireId()])[$entry->requireId()],
        ));
        $replyCard = $this->entityManager->getRepository(LinkCard::class)->findOneBy(['threadPost' => $post]);
        self::assertInstanceOf(LinkCard::class, $replyCard);
        self::assertSame(1, $replyCard->getPosition());
    }

    public function testRootCardsAppendedAfterAThreadCardDoNotCollide(): void
    {
        $cards = $this->repository();
        $entry = $this->entry('one');
        $post = new ThreadPost($entry, 1, new ThreadPostContent(null, null, '<p>r</p>', []));
        $this->entityManager->persist($post);
        $cards->appendToThreadPost($post, $this->link('reply'));
        $this->entityManager->flush();

        $cards->append($entry, [$this->link('a'), $this->link('b')]);
        $this->entityManager->flush();

        self::assertSame([1, 2], $this->rootPositionsOf($entry));
    }

    public function testUnflushedCardsCountWhenNumberingTheNext(): void
    {
        $cards = $this->repository();
        $entry = $this->entry('one');
        $cards->append($entry, [$this->link('a')]);
        $cards->append($entry, [$this->link('b')]);
        $this->entityManager->flush();

        self::assertSame([0, 1], $this->rootPositionsOf($entry));
    }

    public function testStoryLinksReadTheFirstRootCard(): void
    {
        $entry = $this->entry('one');
        $this->repository()->append($entry, [$this->storyLink('first'), $this->storyLink('second')]);
        $this->entityManager->flush();

        $stories = $this->repository()->storyLinksByEntryIds([$entry->requireId()]);

        self::assertSame('first', $stories[$entry->requireId()]->title);
        self::assertSame('Example', $stories[$entry->requireId()]->siteName);
        self::assertSame('About first', $stories[$entry->requireId()]->description);
    }

    public function testStoryLinksSkipThreadCards(): void
    {
        $entry = $this->entry('one');
        $post = new ThreadPost($entry, 1, new ThreadPostContent(null, null, '<p>r</p>', []));
        $this->entityManager->persist($post);
        $this->repository()->appendToThreadPost($post, $this->storyLink('reply'));
        $this->entityManager->flush();

        self::assertSame([], $this->repository()->storyLinksByEntryIds([$entry->requireId()]));
    }

    public function testStoryLinksLeaveOutCardsThatNameNoStory(): void
    {
        $entry = $this->entry('one');
        $frontPage = new LinkCardContent('https://example.com/', 'Home', null, null, 'Example');
        $this->repository()->append($entry, [$this->linkOf($frontPage)]);
        $this->entityManager->flush();

        self::assertSame([], $this->repository()->storyLinksByEntryIds([$entry->requireId()]));
    }

    public function testAResolvedShortLinkKeysOnItsLanding(): void
    {
        $entry = $this->entry('one');
        $short = $this->linkOf(new LinkCardContent('https://n.pr/x', 'Short', null, null, 'Example'));
        $this->repository()->append($entry, [$short]);
        $this->entityManager->flush();
        $landing = 'https://www.npr.org/2026/11/09/a';
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'resolved', resolved_url = ?, resolved_url_hash = ?"
            . ' WHERE shared_link_id = ?',
            [$landing, (new UrlNormalizer())->hash($landing), $short->requireId()],
        );

        $stories = $this->repository()->storyLinksByEntryIds([$entry->requireId()]);

        self::assertSame((new UrlNormalizer())->hash($landing), $stories[$entry->requireId()]->urlHash);
    }

    public function testAShortLinkLandingOnAFrontPageNamesNoStory(): void
    {
        $entry = $this->entry('one');
        $short = $this->linkOf(new LinkCardContent('https://n.pr/x', 'Short', null, null, 'Example'));
        $this->repository()->append($entry, [$short]);
        $this->entityManager->flush();
        $landing = 'https://www.npr.org/';
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'resolved', resolved_url = ?, resolved_url_hash = ?"
            . ' WHERE shared_link_id = ?',
            [$landing, (new UrlNormalizer())->hash($landing), $short->requireId()],
        );

        self::assertSame([], $this->repository()->storyLinksByEntryIds([$entry->requireId()]));
    }

    public function testStoryLinksForNoEntriesIsEmpty(): void
    {
        self::assertSame([], $this->repository()->storyLinksByEntryIds([]));
    }

    private function storyLink(string $title): SharedLink
    {
        return $this->linkOf(
            new LinkCardContent('https://example.com/' . $title, $title, 'About ' . $title, null, 'Example'),
        );
    }

    /** @return list<int> */
    private function rootPositionsOf(Entry $entry): array
    {
        $cards = $this->entityManager->getRepository(LinkCard::class)
            ->findBy(['entry' => $entry, 'threadPost' => null], ['position' => 'ASC']);

        return array_map(static fn (LinkCard $card): int => $card->getPosition(), $cards);
    }

    /**
     * @param list<LinkCardContent> $cards
     *
     * @return list<string>
     */
    private static function titlesOf(array $cards): array
    {
        return array_column($cards, 'title');
    }

    private function link(string $title): SharedLink
    {
        return $this->linkOf(new LinkCardContent('https://example.com/' . $title, $title, null, null, 'example.com'));
    }

    private function linkOf(LinkCardContent $preview): SharedLink
    {
        return SharedLinks::of($this->entityManager, $preview);
    }

    private function entry(string $guid): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '/rss');
        $entry = new Entry(
            $feed,
            $guid,
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

    private function repository(): LinkCardRepository
    {
        $repository = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $repository);

        return $repository;
    }
}
