<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\LinkCardRepository;
use App\Tests\DbTestCase;

final class LinkCardRepositoryTest extends DbTestCase
{
    public function testFindsTheCardAtAPosition(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->append($entry, [], [$this->content('a'), $this->content('b')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame('b', $cards->contentAt($entry->requireId(), 1)->title);
    }

    public function testAPositionPastTheLastCardIsNotFound(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->append($entry, [], [$this->content('a')]);
        $this->entityManager->flush();

        $this->expectException(RecordNotFoundException::class);
        $cards->contentAt($entry->requireId(), 1);
    }

    public function testAnotherEntrysCardIsNotFound(): void
    {
        $holder = $this->entry('holder');
        $other = $this->entry('other');
        $cards = $this->repository();
        $cards->append($holder, [], [$this->content('a')]);
        $this->entityManager->flush();

        $this->expectException(RecordNotFoundException::class);
        $cards->contentAt($other->requireId(), 0);
    }

    public function testAppendingKeepsTheGivenOrder(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();

        $cards->append($entry, [], [$this->content('b'), $this->content('a'), $this->content('c')]);
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

        $cards->append($entry, [], [$full, $bare]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertEquals([$full, $bare], $cards->contentsByEntryIds([$entry->requireId()])[$entry->requireId()]);
    }

    public function testCardsGoWithTheirEntry(): void
    {
        $entry = $this->entry('gone');
        $this->repository()->append($entry, [], [$this->content('a')]);
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
        $cards->append($second, [], [$this->content('s1')]);
        $cards->append($first, [], [$this->content('f1'), $this->content('f2')]);
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
        $cards->append($entry, [], [$this->content('a')]);
        $this->entityManager->flush();

        $cards->append($entry, [$this->content('a')], [$this->content('b'), $this->content('c')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $cards->contentsByEntryIds([$entry->requireId()]);
        self::assertSame(['a', 'b', 'c'], self::titlesOf($stored[$entry->requireId()]));
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

    private function content(string $title): LinkCardContent
    {
        return new LinkCardContent('https://example.com/' . $title, $title, null, null, 'example.com');
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
