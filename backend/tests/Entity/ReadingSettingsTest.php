<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ReadingSettings;
use App\Entity\User;
use App\Enum\MagazineStyle;
use App\Tests\Support\UnsavedUserSettings;
use PHPUnit\Framework\TestCase;

final class ReadingSettingsTest extends TestCase
{
    public function testANewRowHasScrapingOffTheBoxedStyleAndTrendingOn(): void
    {
        $settings = UnsavedUserSettings::reading();

        self::assertFalse($settings->isScrapeFallbackEnabled());
        self::assertSame(MagazineStyle::Boxed, $settings->getMagazineStyle());
        self::assertTrue($settings->isTrendingEnabled());
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->setScrapeFallbackEnabled(true);

        self::assertTrue($settings->isScrapeFallbackEnabled());
    }

    public function testTheMagazineStyleKeepsWhatItIsGiven(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->setMagazineStyle(MagazineStyle::Airy);

        self::assertSame(MagazineStyle::Airy, $settings->getMagazineStyle());
    }

    public function testTrendingKeepsWhatItIsGiven(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->setTrendingEnabled(false);

        self::assertFalse($settings->isTrendingEnabled());
    }

    public function testItBelongsToTheUserItWasBuiltWith(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-11-04 09:00:00'));

        self::assertSame($user, new ReadingSettings($user)->getUser());
    }
}
