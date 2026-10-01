<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
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
