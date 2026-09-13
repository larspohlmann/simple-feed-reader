<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Tests\DbTestCase;

final class EntryCategoryLoaderTest extends DbTestCase
{
    private EntryCategoryLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var EntryCategoryLoader $loader */
        $loader = self::getContainer()->get(EntryCategoryLoader::class);
        $this->loader = $loader;
    }

    public function testLoadsCategoriesInDeclaredOrder(): void
    {
        $feed = new Feed('https://f.test/' . uniqid('', true));
        $this->em->persist($feed);
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, 'g', 'https://x.test/g', 'T', $now, $now, null);
        $this->em->persist($entry);
        $politics = new Category('politics', '');
        $world = new Category('world', '');
        $this->em->persist($politics);
        $this->em->persist($world);
        $this->em->persist(new EntryCategory($entry, $world, 1, 'World'));
        $this->em->persist(new EntryCategory($entry, $politics, 0, 'Politics'));
        $this->em->flush();

        $row = $this->row($entry, new EntryListRowSubscription(1, 'S'));
        $out = $this->loader->loadInto([$row]);

        self::assertSame(['Politics', 'World'], $out[0]->categories);
    }

    public function testEntryWithoutCategoriesGetsEmptyList(): void
    {
        $feed = new Feed('https://f.test/' . uniqid('', true));
        $this->em->persist($feed);
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, 'g2', 'https://x.test/g2', 'T', $now, $now, null);
        $this->em->persist($entry);
        $this->em->flush();

        $row = $this->row($entry, new EntryListRowSubscription(1, 'S'));
        $out = $this->loader->loadInto([$row]);

        self::assertSame([], $out[0]->categories);
    }

    public function testDuplicateRowsAreEnrichedToo(): void
    {
        $feed = new Feed('https://f.test/' . uniqid('', true));
        $this->em->persist($feed);
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $survivor = new Entry($feed, 'g3', 'https://x.test/g3', 'Survivor', $now, $now, null);
        $duplicate = new Entry($feed, 'g4', 'https://x.test/g3', 'Duplicate', $now, $now, null);
        $this->em->persist($survivor);
        $this->em->persist($duplicate);
        $category = new Category('tech', '');
        $this->em->persist($category);
        $this->em->persist(new EntryCategory($duplicate, $category, 0, 'Tech'));
        $this->em->flush();

        $duplicateRow = $this->row($duplicate, new EntryListRowSubscription(2, 'D'));
        $survivorRow = $this->row($survivor, new EntryListRowSubscription(1, 'S'), [$duplicateRow]);

        $out = $this->loader->loadInto([$survivorRow]);

        self::assertSame([], $out[0]->categories);
        self::assertSame(['Tech'], $out[0]->duplicates[0]->categories);
    }

    /** @param list<EntryListRow> $duplicates */
    private function row(Entry $entry, EntryListRowSubscription $subscription, array $duplicates = []): EntryListRow
    {
        return new EntryListRow(
            $entry,
            $subscription,
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
            $duplicates,
        );
    }
}
