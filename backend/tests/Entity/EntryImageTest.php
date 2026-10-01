<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryImage;
use App\Entity\ImageRendition;
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
}
