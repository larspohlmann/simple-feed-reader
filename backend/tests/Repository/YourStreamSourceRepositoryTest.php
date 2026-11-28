<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Enum\FeedLanguage;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use App\Tests\DbTestCase;

final class YourStreamSourceRepositoryTest extends DbTestCase
{
    private YourStreamSourceRepository $sources;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://example.com/source.xml');
        $this->entityManager->persist($this->feed);
        $sources = self::getContainer()->get(YourStreamSourceRepository::class);
        self::assertInstanceOf(YourStreamSourceRepository::class, $sources);
        $this->sources = $sources;
    }

    public function testTheFirstEntryIdSinceIsTheLowestIdAtOrAfterTheInstant(): void
    {
        $this->entry('old', '2026-10-01 00:00:00');
        $boundary = $this->entry('boundary', '2026-10-28 00:00:00');
        $this->entry('recent', '2026-11-20 00:00:00');
        $lateOldDate = $this->entry('older-id-later', '2026-10-15 00:00:00');
        $this->entityManager->flush();

        $first = $this->sources->findFirstEntryIdSince(new \DateTimeImmutable('2026-10-28 00:00:00'));

        self::assertSame($boundary->requireId(), $first);
        self::assertLessThan($lateOldDate->requireId(), $first);
    }

    public function testNoEntryAtOrAfterTheInstantIsNull(): void
    {
        $this->entry('old', '2026-10-01 00:00:00');
        $this->entityManager->flush();

        self::assertNull($this->sources->findFirstEntryIdSince(new \DateTimeImmutable('2026-11-01 00:00:00')));
    }

    public function testEntriesComeInTheAskedOrderAndSkipMissingIds(): void
    {
        $first = $this->entry('first', '2026-10-01 00:00:00');
        $second = $this->entry('second', '2026-10-02 00:00:00');
        $this->entityManager->flush();

        $entries = $this->sources->findEntries([$second->requireId(), 999999, $first->requireId()]);

        self::assertSame(
            [$second->requireId(), $first->requireId()],
            array_map(static fn (StreamEntryModel $entry): int => $entry->id, $entries),
        );
        self::assertSame([], $this->sources->findEntries([]));
    }

    public function testAnEntryWithoutALanguageTakesItsFeedsAndTheEntrysOwnWins(): void
    {
        $this->feed->recordTraits($this->feed->getTraits()->withLanguage(FeedLanguage::German));
        $plain = $this->entry('plain', '2026-10-01 00:00:00');
        $plain->recordText(new EntryText('Kurz', '<p>Kurz und lang</p>', null));
        $french = $this->entry('french', '2026-10-01 00:00:00');
        $french->recordText(new EntryText('Bref', null, FeedLanguage::French));
        $this->entityManager->flush();

        self::assertEquals(
            [
                new StreamEntryModel($plain->requireId(), FeedLanguage::German, 'plain', 'Kurz'),
                new StreamEntryModel($french->requireId(), FeedLanguage::French, 'french', 'Bref'),
            ],
            $this->sources->findEntries([$plain->requireId(), $french->requireId()]),
        );
    }

    public function testTheSummaryIsCutTo2000CharactersAndAMissingOneStaysMissing(): void
    {
        $long = $this->entry('long', '2026-10-01 00:00:00');
        $long->recordText(new EntryText(str_repeat('ä', 2001), null, null));
        $none = $this->entry('none', '2026-10-01 00:00:00');
        $this->entityManager->flush();

        [$cut, $missing] = $this->sources->findEntries([$long->requireId(), $none->requireId()]);

        self::assertSame(str_repeat('ä', 2000), $cut->summary);
        self::assertNull($missing->summary);
    }

    private function entry(string $guid, string $effectiveAt): Entry
    {
        $date = new \DateTimeImmutable($effectiveAt);
        $entry = new Entry($this->feed, $guid, null, $guid, $date, $date);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
