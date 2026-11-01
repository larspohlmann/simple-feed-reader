<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Social\Support\SharedLink;
use PHPUnit\Framework\TestCase;

final class SharedLinkTest extends TestCase
{
    private const string URL = 'https://example.com/article';
    private const string IMAGE_URL = 'https://example.com/lead.jpg';

    public function testTheLinkCarriesTheTitleTheSummaryAndTheLeadImage(): void
    {
        $entry = self::entry();
        $entry->setSummary('<p>The <b>summary</b> wins.</p>');
        $entry->setContentHtml('<p>The body loses.</p>');
        $entry->getImage()->storePending(self::IMAGE_URL, null, null);

        self::assertEquals(
            new SharedLinkModel(self::URL, 'An article', 'The summary wins.', self::IMAGE_URL),
            SharedLink::of($entry, self::URL),
        );
    }

    public function testWithoutASummaryTheBodyDescribesTheLink(): void
    {
        $entry = self::entry();
        $entry->setContentHtml('<p>The body speaks.</p>');

        self::assertSame('The body speaks.', SharedLink::of($entry, self::URL)->description);
    }

    public function testALongDescriptionIsCutAtTheLastWordWithinThreeHundredCharacters(): void
    {
        $entry = self::entry();
        $entry->setSummary(str_repeat('a', 295) . ' bcdefgh');

        self::assertSame(str_repeat('a', 295), SharedLink::of($entry, self::URL)->description);
    }

    public function testADescriptionOfExactlyThreeHundredCharactersIsKeptWhole(): void
    {
        $entry = self::entry();
        $entry->setSummary(str_repeat('a', 295) . ' bcde');

        self::assertSame(str_repeat('a', 295) . ' bcde', SharedLink::of($entry, self::URL)->description);
    }

    public function testAnEntryWithoutTextOrImageHasNeitherDescriptionNorImage(): void
    {
        $link = SharedLink::of(self::entry(), self::URL);

        self::assertNull($link->description);
        self::assertNull($link->imageUrl);
    }

    private static function entry(): Entry
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');

        return new Entry(new Feed('https://example.com/feed'), 'guid-1', self::URL, 'An article', $now, $now);
    }
}
