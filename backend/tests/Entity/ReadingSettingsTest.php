<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ReadingSettings;
use App\Entity\User;
use App\Enum\MagazineStyle;
use PHPUnit\Framework\TestCase;

final class ReadingSettingsTest extends TestCase
{
    public function testANewRowHasScrapingOffTheBoxedStyleAndTrendingOn(): void
    {
        $settings = $this->newSettings();

        self::assertFalse($settings->isScrapeFallbackEnabled());
        self::assertSame(MagazineStyle::Boxed, $settings->getMagazineStyle());
        self::assertTrue($settings->isTrendingEnabled());
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $settings = $this->newSettings();

        $settings->setScrapeFallbackEnabled(true);

        self::assertTrue($settings->isScrapeFallbackEnabled());
    }

    public function testTheMagazineStyleKeepsWhatItIsGiven(): void
    {
        $settings = $this->newSettings();

        $settings->setMagazineStyle(MagazineStyle::Airy);

        self::assertSame(MagazineStyle::Airy, $settings->getMagazineStyle());
    }

    public function testTrendingKeepsWhatItIsGiven(): void
    {
        $settings = $this->newSettings();

        $settings->setTrendingEnabled(false);

        self::assertFalse($settings->isTrendingEnabled());
    }

    public function testItBelongsToTheUserItWasBuiltWith(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-11-04 09:00:00'));

        self::assertSame($user, new ReadingSettings($user)->getUser());
    }

    private function newSettings(): ReadingSettings
    {
        return new ReadingSettings(new User('reader@example.com', new \DateTimeImmutable('2026-11-04 09:00:00')));
    }
}
