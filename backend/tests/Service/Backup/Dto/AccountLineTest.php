<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\DigestSettings;
use App\Entity\ReadingSettings;
use App\Entity\SharingSettings;
use App\Enum\MagazineStyle;
use App\Enum\SocialNetwork;
use App\Service\Account\Model\AccountSettingsModel;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\Model\AccountNetworkSwitchesModel;
use App\Tests\Support\BackupLineRecords;
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

        self::assertSame([SocialNetwork::Bluesky, SocialNetwork::Mastodon], $line->savedSearchIncludedNetworks);
    }

    public function testAForYouExcludedNetworkListedTwiceIsExcludedOnce(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'forYouExcludedNetworks' => ['mastodon', 'mastodon', 'bluesky'],
        ]);

        self::assertSame([SocialNetwork::Mastodon, SocialNetwork::Bluesky], $line->forYouExcludedNetworks);
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
        $settings = new AccountSettingsModel(
            new ReadingSettings($user),
            new SharingSettings($user),
            new DigestSettings($user),
        );
        $settings->reading->setScrapeFallbackEnabled(true);
        $settings->reading->setMagazineStyle(MagazineStyle::Airy);
        $settings->reading->setTrendingEnabled(false);
        $settings->sharing->setHiddenTargets(['mastodon', 'email']);
        $settings->sharing->setRememberingShares(false);
        $networks = new AccountNetworkSwitchesModel([SocialNetwork::Bluesky], [SocialNetwork::Mastodon]);

        self::assertSame(
            '{"kind":"account","locale":"de","scrapeFallbackEnabled":true,"magazineStyle":"airy",'
            . '"sharingEnabled":true,"hiddenShareTargets":["mastodon","email"],"rememberShares":false,'
            . '"trendingEnabled":false,"savedSearchIncludedNetworks":["bluesky"],'
            . '"forYouExcludedNetworks":["mastodon"]}',
            AccountLine::of($user, $settings, $networks)->toBackupLine(),
        );
    }
}
