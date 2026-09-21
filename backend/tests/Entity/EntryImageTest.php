<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryImage;
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
}
