<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\LinkCardLoader;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class LinkCardLoaderTest extends DbTestCase
{
    private LinkCardLoader $loader;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var LinkCardLoader $loader */
        $loader = self::getContainer()->get(LinkCardLoader::class);
        $this->loader = $loader;
        $this->feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager->persist($this->feed);
    }

    public function testEmptyInputReturnsEmptyList(): void
    {
        self::assertSame([], $this->loader->loadInto([]));
    }

    public function testCardsComeBackPerEntryInPositionOrderAndCardlessRowsGetNone(): void
    {
        $withCards = $this->entry('c1');
        $without = $this->entry('c2');
        $this->card($withCards, 1, 'https://b.test/');
        $this->card($withCards, 0, 'https://a.test/');
        $this->entityManager->flush();

        $out = $this->loader->loadInto([$this->row($withCards), $this->row($without)]);

        self::assertSame(['https://a.test/', 'https://b.test/'], array_column($out[0]->linkCards, 'url'));
        self::assertSame([], $out[1]->linkCards);
    }

    public function testDuplicateRowsGetTheirOwnCardsInTheSameQuery(): void
    {
        $survivor = $this->entry('d1');
        $duplicate = $this->entry('d2');
        $this->card($duplicate, 0, 'https://dup.test/');
        $this->entityManager->flush();
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $out = $this->loader->loadInto([$this->row($survivor, [$this->row($duplicate)])]);

        self::assertSame([], $out[0]->linkCards);
        self::assertSame(['https://dup.test/'], array_column($out[0]->duplicates[0]->linkCards, 'url'));
        self::assertCount(1, $recorder->queriesMatching('link_card'), implode("\n", $recorder->queries()));
    }

    private function entry(string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($this->feed, $guid, 'https://x.test/' . $guid, $guid, $now, $now, null);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function card(Entry $entry, int $position, string $url): void
    {
        $this->entityManager->persist(new LinkCard($entry, $position, new LinkCardContent($url, 'T', null, null, 'S')));
    }

    /** @param list<EntryListRow> $duplicates */
    private function row(Entry $entry, array $duplicates = []): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
            $duplicates,
        );
    }
}
