<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ImageRendition;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemImageExtractor;
use PHPUnit\Framework\TestCase;

final class EntryImageWriterTest extends TestCase
{
    public function testANativeHttpsImageWithBothDimensionsIsStoredPendingVerification(): void
    {
        $entry = $this->entry();

        $stored = $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', 800, 600));

        self::assertTrue($stored);
        self::assertSame('https://img.example.com/a.jpg', $entry->getImage()->getUrl());
        self::assertSame(800, $entry->getImage()->getWidth());
        self::assertSame(600, $entry->getImage()->getHeight());
        self::assertNull($entry->getImage()->getCheckedAt());
        self::assertSame(0, $entry->getImage()->getVerifyAttempts());
    }

    public function testAnUpgradedHttpImageIsStoredPendingVerification(): void
    {
        $entry = $this->entry();

        $stored = $this->writer()->write($entry, new DeclaredImageModel('http://img.example.com/a.jpg', 800, 600));

        self::assertTrue($stored);
        self::assertSame('https://img.example.com/a.jpg', $entry->getImage()->getUrl());
        self::assertSame(800, $entry->getImage()->getWidth());
        self::assertSame(600, $entry->getImage()->getHeight());
        self::assertNull($entry->getImage()->getCheckedAt());
    }

    public function testAnImageWithoutAStorableUrlLeavesTheEntryAsItWas(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://img.example.com/old.jpg', null, null);

        $stored = $this->writer()->write($entry, new DeclaredImageModel('/relative.jpg', 800, 600));

        self::assertFalse($stored);
        self::assertSame('https://img.example.com/old.jpg', $entry->getImage()->getUrl());
    }

    public function testStoresTheLadderNarrowestFirstOncePerUrl(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a-1024.jpg', 696, 464, [
            new ImageRendition('https://img.example.com/a-1024.jpg', 1024),
            new ImageRendition('https://img.example.com/a-300.jpg', 300),
            new ImageRendition('https://img.example.com/a-1024.jpg', 696),
        ]));

        self::assertEquals(
            [
                new ImageRendition('https://img.example.com/a-300.jpg', 300),
                new ImageRendition('https://img.example.com/a-1024.jpg', 1024),
            ],
            $entry->getImage()->getRenditions(),
        );
    }

    public function testUpgradesHttpRenditionsAndDropsUnstorableOnes(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', null, null, [
            new ImageRendition('http://img.example.com/a-300.jpg', 300),
            new ImageRendition('/relative-600.jpg', 600),
            new ImageRendition('//img.example.com/a-900.jpg', 900),
        ]));

        self::assertEquals(
            [
                new ImageRendition('https://img.example.com/a-300.jpg', 300),
                new ImageRendition('https://img.example.com/a-900.jpg', 900),
            ],
            $entry->getImage()->getRenditions(),
        );
    }

    public function testDropsARenditionWhoseUrlHoldsWhitespace(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', null, null, [
            new ImageRendition('https://img.example.com/a-300.jpg', 300),
            new ImageRendition('https://img.example.com/a 600.jpg', 600),
            new ImageRendition("https://img.example.com/a-700.jpg\t", 700),
            new ImageRendition('https://img.example.com/a-900.jpg', 900),
        ]));

        self::assertEquals(
            [
                new ImageRendition('https://img.example.com/a-300.jpg', 300),
                new ImageRendition('https://img.example.com/a-900.jpg', 900),
            ],
            $entry->getImage()->getRenditions(),
        );
    }

    public function testASingleRenditionIsNoLadder(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', 800, 600, [
            new ImageRendition('https://img.example.com/a.jpg', 800),
            new ImageRendition('http://img.example.com/a.jpg', 800),
        ]));

        self::assertSame([], $entry->getImage()->getRenditions());
    }

    public function testASingleRenditionOfTheLeadImageUpgradedToHttpsIsNoLadder(): void
    {
        $entry = $this->entry();

        $this->writer()->write($entry, new DeclaredImageModel('http://img.example.com/a.jpg', null, null, [
            new ImageRendition('https://img.example.com/a.jpg', 800),
        ]));

        self::assertSame([], $entry->getImage()->getRenditions());
    }

    public function testASingleRenditionBesideAnUnmeasuredLeadIsServedAndToppedOnceTheLeadIsMeasured(): void
    {
        $entry = $this->entry();
        $rendition = new ImageRendition('https://img.example.com/a-800.jpg', 800);

        $image = new DeclaredImageModel('https://img.example.com/a.jpg', null, null, [$rendition]);

        $this->writer()->write($entry, $image);

        self::assertEquals([$rendition], $entry->getImage()->getRenditions());
        self::assertEquals([$rendition], $entry->getImage()->servedRenditions());

        $entry->getImage()->recordMeasurement(3000, 2000, new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertEquals(
            [$rendition, new ImageRendition('https://img.example.com/a.jpg', 3000)],
            $entry->getImage()->servedRenditions(),
        );
    }

    public function testAnotherImageReplacesTheStoredLadder(): void
    {
        $entry = $this->entry();
        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/a.jpg', null, null, [
            new ImageRendition('https://img.example.com/a-300.jpg', 300),
            new ImageRendition('https://img.example.com/a-900.jpg', 900),
        ]));

        $this->writer()->write($entry, new DeclaredImageModel('https://img.example.com/b.jpg', 800, 600));

        self::assertSame([], $entry->getImage()->getRenditions());
    }

    public function testNoDeclaredImageMarksTheEntryAsHavingNone(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://img.example.com/old.jpg', 10, 10);

        $this->writer()->writeOrMarkNone($entry, null);

        self::assertNull($entry->getImage()->getUrl());
        self::assertTrue($entry->getImage()->isMissing());
    }

    public function testAnUnstorableDeclaredImageAlsoMarksTheEntryAsHavingNone(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://img.example.com/old.jpg', 10, 10);

        $this->writer()->writeOrMarkNone($entry, new DeclaredImageModel('/relative.jpg', 800, 600));

        self::assertNull($entry->getImage()->getUrl());
        self::assertTrue($entry->getImage()->isMissing());
    }

    public function testAStorableDeclaredImageIsWritten(): void
    {
        $entry = $this->entry();

        $this->writer()->writeOrMarkNone($entry, new DeclaredImageModel('https://img.example.com/a.jpg', 800, 600));

        self::assertSame('https://img.example.com/a.jpg', $entry->getImage()->getUrl());
    }

    public function testAThumbnailLadderBesideAnEnclosureIsServedOnlyOnceTheEnclosureTopsIt(): void
    {
        $entry = $this->entry();

        $this->writeEnclosureWithThumbnailExcerpt($entry);

        self::assertCount(3, $entry->getImage()->getRenditions());
        self::assertSame([], $entry->getImage()->servedRenditions());

        $entry->getImage()->recordMeasurement(1600, 1067, new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertEquals(
            [
                new ImageRendition('https://img.example.com/2026/09/photo-50x50.jpg', 50),
                new ImageRendition('https://img.example.com/2026/09/photo-100x100.jpg', 100),
                new ImageRendition('https://img.example.com/2026/09/photo-150x150.jpg', 150),
                new ImageRendition('https://img.example.com/2026/09/photo.jpg', 1600),
            ],
            $entry->getImage()->servedRenditions(),
        );
    }

    private function writeEnclosureWithThumbnailExcerpt(Entry $entry): void
    {
        $folder = 'https://img.example.com/2026/09/';
        $document = new \DOMDocument();
        $document->loadXML('<rss><channel><item><enclosure url="' . $folder . 'photo.jpg" length="0"'
            . ' type="image/jpeg"/></item></channel></rss>');
        $item = $document->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);
        $excerpt = '<img width="150" height="150" src="' . $folder . 'photo-150x150.jpg" srcset="'
            . $folder . 'photo-150x150.jpg 150w, ' . $folder . 'photo-100x100.jpg 100w, '
            . $folder . 'photo-50x50.jpg 50w">';

        $image = new FeedItemImageSelector(new ItemImageExtractor())->fromRss2($item, $excerpt);
        self::assertNotNull($image);
        $this->writer()->write($entry, $image);
    }

    private function writer(): EntryImageWriter
    {
        return new EntryImageWriter();
    }

    private function entry(): Entry
    {
        $at = new \DateTimeImmutable('2026-09-21 12:00:00');

        return new Entry(new Feed('https://example.com/feed'), 'g-1', 'https://example.com/1', 'Title', $at, $at);
    }
}
