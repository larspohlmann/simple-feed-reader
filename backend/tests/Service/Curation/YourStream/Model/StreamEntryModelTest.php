<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream\Model;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use PHPUnit\Framework\TestCase;

final class StreamEntryModelTest extends TestCase
{
    public function testTheEntrysLanguageWinsOverItsFeeds(): void
    {
        $entry = $this->entry(FeedLanguage::German, 'Kurz');
        $entry->recordText(new EntryText('Kurz', null, FeedLanguage::French));

        self::assertSame(FeedLanguage::French, StreamEntryModel::fromEntry($entry)->language);
    }

    public function testAnEntryWithoutALanguageTakesItsFeeds(): void
    {
        self::assertEquals(
            new StreamEntryModel(5, FeedLanguage::German, 'Headline', 'Kurz'),
            StreamEntryModel::fromEntry($this->entry(FeedLanguage::German, 'Kurz')),
        );
    }

    public function testTheSummaryIsCutTo2000Characters(): void
    {
        $summary = StreamEntryModel::fromEntry($this->entry(null, str_repeat('ä', 2001)))->summary;

        self::assertSame(str_repeat('ä', 2000), $summary);
    }

    public function testAMissingSummaryStaysMissing(): void
    {
        self::assertNull(StreamEntryModel::fromEntry($this->entry(null, null))->summary);
    }

    private function entry(?FeedLanguage $feedLanguage, ?string $summary): Entry
    {
        $feed = new Feed('https://model.example.com/feed.xml');
        $feed->recordTraits($feed->getTraits()->withLanguage($feedLanguage));
        $at = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, 'guid', null, 'Headline', $at, $at);
        $entry->recordText(new EntryText($summary, null, null));
        $reflection = new \ReflectionProperty(Entry::class, 'id');
        $reflection->setValue($entry, 5);

        return $entry;
    }
}
