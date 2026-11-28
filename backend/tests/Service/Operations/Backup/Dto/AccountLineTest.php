<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Dto;

use App\Enum\Handedness;
use App\Enum\InclusionSurface;
use App\Enum\MagazineStyle;
use App\Enum\ReadingWidth;
use App\Enum\SidebarStyle;
use App\Enum\SocialNetwork;
use App\Enum\Theme;
use App\Service\Operations\Backup\Dto\AccountLine;
use App\Service\Operations\Backup\Exception\InvalidBackupException;
use App\Service\Operations\Backup\Model\AccountNetworkSwitchesModel;
use App\Service\Sourcing\FeedDirectory\Model\FeedDirectoryScopeModel;
use App\Tests\Support\BackupLineRecords;
use App\Tests\Support\UnsavedUserSettings;
use PHPUnit\Framework\TestCase;

final class AccountLineTest extends TestCase
{
    public function testAnOlderBackupSharesEverything(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertTrue($line->sharingEnabled);
        self::assertSame([], $line->hiddenShareTargets);
    }

    public function testAnOlderBackupShowsTrending(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertTrue($line->reading->trendingEnabled);
    }

    public function testItReadsTrending(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true, 'trendingEnabled' => false]);

        self::assertFalse($line->reading->trendingEnabled);
    }

    public function testAnOlderBackupHasNoFeedDirectoryScope(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertEquals(new FeedDirectoryScopeModel([], []), $line->feedDirectoryScope);
    }

    public function testItReadsTheFeedDirectoryScope(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'feedDirectoryScope' => ['countries' => ['DE', 'AT'], 'languages' => ['de']],
        ]);

        self::assertSame(['DE', 'AT'], $line->feedDirectoryScope->countries);
        self::assertSame(['de'], $line->feedDirectoryScope->languages);
    }

    public function testAHalfScopeRestoresAsNoScope(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'feedDirectoryScope' => ['countries' => ['DE'], 'languages' => []],
        ]);

        self::assertEquals(new FeedDirectoryScopeModel([], []), $line->feedDirectoryScope);
    }

    public function testItReadsSharing(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'sharingEnabled' => false,
            'hiddenShareTargets' => ['facebook', 'x'],
        ]);

        self::assertFalse($line->sharingEnabled);
        self::assertSame(['facebook', 'x'], $line->hiddenShareTargets);
    }

    public function testAnOlderBackupRemembersShares(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertTrue($line->rememberShares);
    }

    public function testItReadsTheRememberSharesSetting(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'rememberShares' => false,
        ]);

        self::assertFalse($line->rememberShares);
    }

    public function testItReadsTheMagazineStyle(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'magazineStyle' => 'airy',
        ]);

        self::assertSame(MagazineStyle::Airy, $line->reading->magazineStyle);
    }

    public function testAMissingMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(MagazineStyle::Boxed, $line->reading->magazineStyle);
    }

    public function testAnInvalidMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'magazineStyle' => 'sideways',
        ]);

        self::assertSame(MagazineStyle::Boxed, $line->reading->magazineStyle);
    }

    public function testItReadsTheSidebarStyle(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'sidebarStyle' => 'airy',
        ]);

        self::assertSame(SidebarStyle::Airy, $line->reading->sidebarStyle);
    }

    public function testAMissingSidebarStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(SidebarStyle::Boxed, $line->reading->sidebarStyle);
    }

    public function testAnInvalidSidebarStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'sidebarStyle' => 'sideways',
        ]);

        self::assertSame(SidebarStyle::Boxed, $line->reading->sidebarStyle);
    }

    public function testANonStringSidebarStyleIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Field "sidebarStyle" is missing or not a string.');

        AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true, 'sidebarStyle' => 5]);
    }

    public function testItReadsTheTheme(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'theme' => 'classic',
        ]);

        self::assertSame(Theme::Classic, $line->reading->theme);
    }

    public function testAMissingThemeFallsBackToGraphite(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(Theme::Graphite, $line->reading->theme);
    }

    public function testAnInvalidThemeFallsBackToGraphite(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'theme' => 'sepia',
        ]);

        self::assertSame(Theme::Graphite, $line->reading->theme);
    }

    public function testANonStringThemeIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Field "theme" is missing or not a string.');

        AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true, 'theme' => 5]);
    }

    public function testItReadsTheHandedness(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'handedness' => 'left',
        ]);

        self::assertSame(Handedness::Left, $line->reading->handedness);
    }

    public function testAMissingHandednessFallsBackToRight(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(Handedness::Right, $line->reading->handedness);
    }

    public function testAnInvalidHandednessFallsBackToRight(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'handedness' => 'both',
        ]);

        self::assertSame(Handedness::Right, $line->reading->handedness);
    }

    public function testItReadsTheReadingWidth(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'readingWidth' => 'wide',
        ]);

        self::assertSame(ReadingWidth::Wide, $line->reading->readingWidth);
    }

    public function testAMissingReadingWidthFallsBackToStandard(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(ReadingWidth::Standard, $line->reading->readingWidth);
    }

    public function testAnUnknownReadingWidthFallsBackToStandard(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'readingWidth' => 'huge',
        ]);

        self::assertSame(ReadingWidth::Standard, $line->reading->readingWidth);
    }

    /** An older backup's `recommendationSettings` object is ignored, not rejected: the format does not carry it. */
    public function testALegacyRecommendationSettingsBlockIsIgnored(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => false,
            'magazineStyle' => 'airy',
            'recommendationSettings' => ['guidancePrompt' => 'Only long reads.', 'batchCount' => 3],
        ]);

        self::assertSame('de', $line->locale);
        self::assertFalse($line->reading->scrapeFallbackEnabled);
        self::assertSame(MagazineStyle::Airy, $line->reading->magazineStyle);
    }

    public function testANetworkListedTwiceIsIncludedOnce(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'savedSearchIncludedNetworks' => ['bluesky', 'bluesky', 'mastodon'],
        ]);

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $line->networks->savedSearchIncludedNetworks,
        );
    }

    public function testARestoreKeepsLemmy(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'savedSearchIncludedNetworks' => ['bluesky', 'lemmy', 'myspace'],
        ]);

        self::assertSame([SocialNetwork::Bluesky, SocialNetwork::Lemmy], $line->networks->savedSearchIncludedNetworks);
    }

    public function testAForYouExcludedNetworkListedTwiceIsExcludedOnce(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'forYouExcludedNetworks' => ['mastodon', 'mastodon', 'bluesky'],
        ]);

        self::assertSame(
            [SocialNetwork::Mastodon, SocialNetwork::Bluesky],
            $line->networks->excludedFrom(InclusionSurface::ForYou),
        );
    }

    public function testAnAllItemsExcludedNetworkListedTwiceIsExcludedOnce(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'allItemsExcludedNetworks' => ['bluesky', 'bluesky', 'mastodon'],
        ]);

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $line->networks->excludedFrom(InclusionSurface::AllItems),
        );
    }

    public function testALineWithoutAllItemsExclusionsExcludesNoNetwork(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame([], $line->networks->excludedFrom(InclusionSurface::AllItems));
    }

    public function testATrendingExcludedNetworkListedTwiceIsExcludedOnce(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'trendingExcludedNetworks' => ['mastodon', 'mastodon'],
        ]);

        self::assertSame([SocialNetwork::Mastodon], $line->networks->excludedFrom(InclusionSurface::Trending));
    }

    public function testALineWithoutTrendingExclusionsExcludesNoNetwork(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame([], $line->networks->excludedFrom(InclusionSurface::Trending));
    }

    public function testANonStringMagazineStyleIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Field "magazineStyle" is missing or not a string.');

        AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true, 'magazineStyle' => 5]);
    }

    public function testItWritesTheAccountsSettingsAndNetworkSwitches(): void
    {
        $user = BackupLineRecords::user();
        $user->setLocale('de');
        $settings = UnsavedUserSettings::of($user);
        $settings->reading->setScrapeFallbackEnabled(true);
        $settings->reading->setMagazineStyle(MagazineStyle::Airy);
        $settings->reading->setSidebarStyle(SidebarStyle::Airy);
        $settings->reading->setTheme(Theme::Classic);
        $settings->reading->setHandedness(Handedness::Left);
        $settings->reading->setReadingWidth(ReadingWidth::Wide);
        $settings->reading->setTrendingEnabled(false);
        $settings->sharing->setHiddenTargets(['mastodon', 'email']);
        $settings->sharing->setRememberingShares(false);
        $settings->feedDirectoryScope->change(['DE'], ['de']);
        $networks = new AccountNetworkSwitchesModel([SocialNetwork::Bluesky], [
            InclusionSurface::AllItems->value => [SocialNetwork::Bluesky],
            InclusionSurface::ForYou->value => [SocialNetwork::Mastodon],
            InclusionSurface::Trending->value => [SocialNetwork::Mastodon],
        ]);

        self::assertSame(
            '{"kind":"account","locale":"de","scrapeFallbackEnabled":true,"magazineStyle":"airy",'
            . '"sidebarStyle":"airy","theme":"classic","handedness":"left","readingWidth":"wide","sharingEnabled":true,'
            . '"hiddenShareTargets":["mastodon","email"],'
            . '"rememberShares":false,'
            . '"trendingEnabled":false,"feedDirectoryScope":{"countries":["DE"],"languages":["de"]},'
            . '"savedSearchIncludedNetworks":["bluesky"],'
            . '"forYouExcludedNetworks":["mastodon"],'
            . '"allItemsExcludedNetworks":["bluesky"],'
            . '"trendingExcludedNetworks":["mastodon"]}',
            AccountLine::fromAccount($user, $settings, $networks)->toBackupLine(),
        );
    }
}
