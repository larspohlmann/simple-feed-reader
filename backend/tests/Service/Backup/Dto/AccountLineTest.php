<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Enum\InclusionSurface;
use App\Enum\Handedness;
use App\Enum\MagazineStyle;
use App\Enum\SocialNetwork;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\Model\AccountNetworkSwitchesModel;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
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

        self::assertTrue($line->trendingEnabled);
    }

    public function testItReadsTrending(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true, 'trendingEnabled' => false]);

        self::assertFalse($line->trendingEnabled);
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

        self::assertSame(MagazineStyle::Airy, $line->magazineStyle);
    }

    public function testAMissingMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(MagazineStyle::Boxed, $line->magazineStyle);
    }

    public function testAnInvalidMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'magazineStyle' => 'sideways',
        ]);

        self::assertSame(MagazineStyle::Boxed, $line->magazineStyle);
    }

    public function testItReadsTheHandedness(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'handedness' => 'left',
        ]);

        self::assertSame(Handedness::Left, $line->handedness);
    }

    public function testAMissingHandednessFallsBackToRight(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(Handedness::Right, $line->handedness);
    }

    public function testAnInvalidHandednessFallsBackToRight(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'handedness' => 'both',
        ]);

        self::assertSame(Handedness::Right, $line->handedness);
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
        self::assertFalse($line->scrapeFallbackEnabled);
        self::assertSame(MagazineStyle::Airy, $line->magazineStyle);
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
        $settings->reading->setHandedness(Handedness::Left);
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
            . '"handedness":"left","sharingEnabled":true,"hiddenShareTargets":["mastodon","email"],'
            . '"rememberShares":false,'
            . '"trendingEnabled":false,"feedDirectoryScope":{"countries":["DE"],"languages":["de"]},'
            . '"savedSearchIncludedNetworks":["bluesky"],'
            . '"forYouExcludedNetworks":["mastodon"],'
            . '"allItemsExcludedNetworks":["bluesky"],'
            . '"trendingExcludedNetworks":["mastodon"]}',
            AccountLine::of($user, $settings, $networks)->toBackupLine(),
        );
    }
}
