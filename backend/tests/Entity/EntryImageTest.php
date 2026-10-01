<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryImage;
use App\Entity\ImageRendition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryImageTest extends TestCase
{
    public function testANewImageIsMissing(): void
    {
        $image = new EntryImage();

        self::assertTrue($image->isMissing());
        self::assertSame(0, $image->getVerifyAttempts());
    }

    public function testStoringAUrlPendingQueuesIt(): void
    {
        $image = new EntryImage();

        $image->storePending('https://i/a.jpg', null, null);

        self::assertSame('https://i/a.jpg', $image->getUrl());
        self::assertNull($image->getCheckedAt());
        self::assertFalse($image->isMissing());
    }

    public function testStoringNoUrlPendingQueuesNothing(): void
    {
        $image = new EntryImage();

        $image->storePending(null, null, null);

        self::assertTrue($image->isMissing());
    }

    public function testDroppingLeavesATombstone(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', null, null);
        $image->recordFailedProbe();
        $at = new \DateTimeImmutable('2026-09-21 12:00:00');

        $image->drop($at);

        self::assertNull($image->getUrl());
        self::assertNull($image->getWidth());
        self::assertNull($image->getHeight());
        self::assertEquals($at, $image->getCheckedAt());
        self::assertSame(0, $image->getVerifyAttempts());
        self::assertFalse($image->isMissing());
    }

    public function testKeepingUnmeasuredSettlesWithoutTouchingTheDimensions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 640, 360);
        $image->recordFailedProbe();
        $at = new \DateTimeImmutable('2026-09-21 12:00:00');

        $image->keepUnmeasured($at);

        self::assertSame('https://i/a.jpg', $image->getUrl());
        self::assertSame(640, $image->getWidth());
        self::assertSame(360, $image->getHeight());
        self::assertEquals($at, $image->getCheckedAt());
        self::assertSame(0, $image->getVerifyAttempts());
    }

    public function testAMeasurementClearsTheRetryCounter(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', null, null);
        $image->recordFailedProbe();
        $at = new \DateTimeImmutable('2026-09-21 12:00:00');

        $image->recordMeasurement(600, 400, $at);

        self::assertSame(0, $image->getVerifyAttempts());
        self::assertSame(600, $image->getWidth());
        self::assertSame(400, $image->getHeight());
        self::assertEquals($at, $image->getCheckedAt());
    }

    public function testStoredRenditionsReadBack(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a-1024.jpg', 1024, 683);
        $renditions = [
            new ImageRendition('https://i/a-300.jpg', 300),
            new ImageRendition('https://i/a-1024.jpg', 1024),
        ];

        $image->storeRenditions($renditions);

        self::assertEquals($renditions, $image->getRenditions());
    }

    public function testAnIncompleteStoredRenditionReadsBackAsNone(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', null, null);

        (new \ReflectionProperty(EntryImage::class, 'renditions'))->setValue($image, [
            ['url' => 'https://i/a-150.jpg'],
            ['url' => 'https://i/a-300.jpg', 'width' => 300],
        ]);

        self::assertEquals([new ImageRendition('https://i/a-300.jpg', 300)], $image->getRenditions());
    }

    public function testStoringNoRenditionsReadsBackAsNone(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', null, null);

        $image->storeRenditions([]);

        self::assertSame([], $image->getRenditions());
    }

    public function testStoringAnotherImageClearsTheRenditions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 1024, 683);
        $image->storeRenditions([new ImageRendition('https://i/a-300.jpg', 300)]);

        $image->storePending('https://i/b.jpg', 800, 600);

        self::assertSame([], $image->getRenditions());
    }

    public function testDroppingClearsTheRenditions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 1024, 683);
        $image->storeRenditions([new ImageRendition('https://i/a-300.jpg', 300)]);

        $image->drop(new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertSame([], $image->getRenditions());
    }

    public function testMeasuringTheImageKeepsItsRenditions(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/a.jpg', 1024, 683);
        $renditions = [new ImageRendition('https://i/a-300.jpg', 300)];
        $image->storeRenditions($renditions);

        $image->recordMeasurement(1024, 683, new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertEquals($renditions, $image->getRenditions());
    }

    public function testServesNoRenditionsWhenNoneAreStored(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', 1200, 800);

        self::assertSame([], $image->servedRenditions());
    }

    public function testServesTheStoredRenditionsWhenTheyHoldTheLeadImage(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo-1024.jpg', null, null);
        $renditions = self::thumbnailLadder('https://i/photo-1024.jpg', 1024);
        $image->storeRenditions($renditions);

        self::assertEquals($renditions, $image->servedRenditions());
    }

    public function testServesTheLeadImageAsTheTopRungWhenItIsWiderThanTheLadder(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', 1200, 800);
        $image->storeRenditions(self::thumbnailLadder());

        self::assertEquals(
            [...self::thumbnailLadder(), new ImageRendition('https://i/photo.jpg', 1200)],
            $image->servedRenditions(),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function leadWidthNoWiderThanTheLadderProvider(): iterable
    {
        yield 'as wide as the widest rung' => [150];
        yield 'narrower than the widest rung' => [120];
    }

    #[DataProvider('leadWidthNoWiderThanTheLadderProvider')]
    public function testServesTheStoredRenditionsWhenTheLeadImageIsNoWider(int $leadWidth): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', $leadWidth, $leadWidth);
        $image->storeRenditions(self::thumbnailLadder());

        self::assertEquals(self::thumbnailLadder(), $image->servedRenditions());
    }

    public function testServesNoRenditionsWhileTheLeadImageWidthIsUnknown(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', null, null);
        $image->storeRenditions(self::thumbnailLadder());

        self::assertSame([], $image->servedRenditions());
    }

    /** @return iterable<string, array{int}> */
    public static function ladderWideEnoughForTheWidestListSlotProvider(): iterable
    {
        yield 'exactly the widest list slot' => [680];
        yield 'wider than the widest list slot' => [1456];
    }

    #[DataProvider('ladderWideEnoughForTheWidestListSlotProvider')]
    public function testServesALadderFillingTheWidestListSlotWhileTheLeadWidthIsUnknown(int $widest): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', null, null);
        $renditions = [
            new ImageRendition('https://i/photo-424.jpg', 424),
            new ImageRendition('https://i/photo-wide.jpg', $widest),
        ];
        $image->storeRenditions($renditions);

        self::assertEquals($renditions, $image->servedRenditions());
    }

    public function testServesNoLadderJustNarrowerThanTheWidestListSlotWhileTheLeadWidthIsUnknown(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', null, null);
        $image->storeRenditions(self::thumbnailLadder('https://i/photo-679.jpg', 679));

        self::assertSame([], $image->servedRenditions());
    }

    public function testServesAWideLadderOfAnImageKeptUnmeasured(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', null, null);
        $renditions = [
            new ImageRendition('https://i/photo-424.jpg', 424),
            new ImageRendition('https://i/photo-1456.jpg', 1456),
        ];
        $image->storeRenditions($renditions);

        $image->keepUnmeasured(new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertEquals($renditions, $image->servedRenditions());
    }

    public function testServesTheLeadImageOnTopOnceTheVerifierMeasuredIt(): void
    {
        $image = new EntryImage();
        $image->storePending('https://i/photo.jpg', null, null);
        $image->storeRenditions(self::thumbnailLadder());

        $image->recordMeasurement(1600, 1067, new \DateTimeImmutable('2026-10-01 12:00:00'));

        self::assertEquals(
            [...self::thumbnailLadder(), new ImageRendition('https://i/photo.jpg', 1600)],
            $image->servedRenditions(),
        );
    }

    /** @return list<ImageRendition> */
    private static function thumbnailLadder(string $widestUrl = 'https://i/photo-150x150.jpg', int $widest = 150): array
    {
        return [
            new ImageRendition('https://i/photo-50x50.jpg', 50),
            new ImageRendition('https://i/photo-100x100.jpg', 100),
            new ImageRendition($widestUrl, $widest),
        ];
    }
}
