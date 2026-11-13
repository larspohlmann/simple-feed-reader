<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ReadingSettings;
use App\Entity\ReadingSettingsChange;
use App\Entity\User;
use App\Enum\Handedness;
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

    public function testANewAccountIsRightHanded(): void
    {
        self::assertSame(Handedness::Right, UnsavedUserSettings::reading()->getHandedness());
    }

    public function testTheHandednessKeepsWhatItIsGiven(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->setHandedness(Handedness::Left);

        self::assertSame(Handedness::Left, $settings->getHandedness());
    }

    public function testTrendingKeepsWhatItIsGiven(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->setTrendingEnabled(false);

        self::assertFalse($settings->isTrendingEnabled());
    }

    public function testApplyChangesOnlyTheGivenSettings(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->apply(new ReadingSettingsChange(magazineStyle: MagazineStyle::Airy, handedness: Handedness::Left));

        self::assertSame(MagazineStyle::Airy, $settings->getMagazineStyle());
        self::assertSame(Handedness::Left, $settings->getHandedness());
        self::assertFalse($settings->isScrapeFallbackEnabled());
        self::assertTrue($settings->isTrendingEnabled());
    }

    public function testApplyWithNothingGivenKeepsEverything(): void
    {
        $settings = UnsavedUserSettings::reading();

        $settings->apply(new ReadingSettingsChange());

        self::assertFalse($settings->isScrapeFallbackEnabled());
        self::assertSame(MagazineStyle::Boxed, $settings->getMagazineStyle());
        self::assertSame(Handedness::Right, $settings->getHandedness());
        self::assertTrue($settings->isTrendingEnabled());
    }

    public function testItBelongsToTheUserItWasBuiltWith(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-11-04 09:00:00'));

        self::assertSame($user, new ReadingSettings($user)->getUser());
    }
}
