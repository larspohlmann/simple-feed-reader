<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Repository\LinkCardRepository;
use App\Tests\DbTestCase;

final class LinkCardRepositoryTest extends DbTestCase
{
    public function testReplacingKeepsTheGivenOrder(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();

        $cards->replaceFor($entry, [$this->content('b'), $this->content('a'), $this->content('c')]);
        $this->entityManager->flush();

        $stored = $cards->findByEntryIds([$entry->requireId()]);
        self::assertSame(['b', 'a', 'c'], self::titlesOf($stored[$entry->requireId()]));
    }

    public function testReplacingTwiceLeavesOnlyTheSecondSet(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->replaceFor($entry, [$this->content('a'), $this->content('b')]);
        $this->entityManager->flush();

        $cards->replaceFor($entry, [$this->content('c')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $cards->findByEntryIds([$entry->requireId()]);
        self::assertSame(['c'], self::titlesOf($stored[$entry->requireId()]));
        self::assertCount(1, $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM link_card'));
    }

    public function testReplacingCardsAlreadyLoadedLeavesOnlyTheNewSet(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->replaceFor($entry, [$this->content('a'), $this->content('b')]);
        $this->entityManager->flush();
        $cards->findByEntryIds([$entry->requireId()]);

        $cards->replaceFor($entry, [$this->content('c'), $this->content('d')]);
        $this->entityManager->flush();

        $stored = $cards->findByEntryIds([$entry->requireId()]);
        self::assertSame(['c', 'd'], self::titlesOf($stored[$entry->requireId()]));
        self::assertCount(2, $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM link_card'));
    }

    public function testStoresTheOptionalFieldsAsGiven(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $full = new LinkCardContent('https://example.com/a', 'Title', 'About', 'https://example.com/a.jpg', 'Example');
        $bare = new LinkCardContent('https://example.com/b', 'Bare', null, null, 'example.com');

        $cards->replaceFor($entry, [$full, $bare]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        [$first, $second] = $cards->findByEntryIds([$entry->requireId()])[$entry->requireId()];
        self::assertEquals($full, $first->getContent());
        self::assertEquals($bare, $second->getContent());
    }

    public function testTellsWhetherAnEntryHasAnyCard(): void
    {
        $with = $this->entry('with');
        $without = $this->entry('without');
        $cards = $this->repository();
        $cards->replaceFor($with, [$this->content('a')]);
        $this->entityManager->flush();

        self::assertTrue($cards->hasAnyFor($with));
        self::assertFalse($cards->hasAnyFor($without));
    }

    public function testCardsGoWithTheirEntry(): void
    {
        $entry = $this->entry('gone');
        $this->repository()->replaceFor($entry, [$this->content('a')]);
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
        $cards->replaceFor($first, [$this->content('f1'), $this->content('f2')]);
        $cards->replaceFor($second, [$this->content('s1')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $grouped = $cards->findByEntryIds([$first->requireId(), $second->requireId(), $empty->requireId()]);

        self::assertSame([$first->requireId(), $second->requireId()], array_keys($grouped));
        self::assertSame(['f1', 'f2'], self::titlesOf($grouped[$first->requireId()]));
        self::assertSame(['s1'], self::titlesOf($grouped[$second->requireId()]));
    }

    public function testFindingForNoEntriesIsEmpty(): void
    {
        self::assertSame([], $this->repository()->findByEntryIds([]));
    }

    /**
     * @param list<LinkCard> $cards
     *
     * @return list<string>
     */
    private static function titlesOf(array $cards): array
    {
        return array_map(static fn (LinkCard $card): string => $card->getContent()->title, $cards);
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
