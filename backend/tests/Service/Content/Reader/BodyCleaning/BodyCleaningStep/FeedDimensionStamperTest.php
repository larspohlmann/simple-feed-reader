<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\FeedDimensionStamper;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Model\FeedMediaModel;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class FeedDimensionStamperTest extends TestCase
{
    use ParsesHtml;

    /** @param list<EntryMedium> $media */
    private function feed(array $media): FeedMediaModel
    {
        $entry = new Entry(
            new Feed('https://feed.test/rss.xml'),
            'g1',
            'https://feed.test/article',
            'Title',
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
        );
        $entry->setMedia($media, []);

        return FeedMediaModel::fromEntry($entry);
    }

    private function stamp(string $bodyHtml, FeedMediaModel $feed): string
    {
        $document = $this->document(
            '<!doctype html><html><body>' . $bodyHtml . '</body></html>'
        );
        (new FeedDimensionStamper())->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withFeedMedia($feed)),
        );

        return $document->saveHtml();
    }

    public function testStampsFeedDimensionsOnAMatchingImage(): void
    {
        $feed = $this->feed([new EntryMedium('https://img.test/harbour-crane.jpg', 'image', 1600, 900)]);

        $out = $this->stamp('<img src="https://cdn.test/w:800/harbour-crane.jpg">', $feed);

        self::assertStringContainsString('width="1600"', $out);
        self::assertStringContainsString('height="900"', $out);
    }

    public function testLeavesANonMatchingImageAlone(): void
    {
        $feed = $this->feed([new EntryMedium('https://img.test/harbour-crane.jpg', 'image', 1600, 900)]);

        $out = $this->stamp('<img src="https://cdn.test/city-skyline.jpg">', $feed);

        self::assertStringNotContainsString('width=', $out);
    }

    public function testNeverOverwritesDimensionsTheImageAlreadyCarries(): void
    {
        $feed = $this->feed([new EntryMedium('https://img.test/harbour-crane.jpg', 'image', 1600, 900)]);

        $out = $this->stamp('<img src="https://img.test/harbour-crane.jpg" width="400" height="225">', $feed);

        self::assertStringContainsString('width="400"', $out);
        self::assertStringNotContainsString('width="1600"', $out);
    }

    public function testStampsAMatchingVideo(): void
    {
        $feed = $this->feed([new EntryMedium('https://cdn.test/clip.mp4', 'video', 1280, 720)]);

        $out = $this->stamp('<video src="https://cdn.test/clip.mp4" controls></video>', $feed);

        self::assertStringContainsString('width="1280"', $out);
        self::assertStringContainsString('height="720"', $out);
    }

    public function testSkipsAMediumMissingEitherDimension(): void
    {
        $feed = $this->feed([new EntryMedium('https://img.test/harbour-crane.jpg', 'image', 1600, null)]);

        $out = $this->stamp('<img src="https://img.test/harbour-crane.jpg">', $feed);

        self::assertStringNotContainsString('width=', $out);
    }
}
