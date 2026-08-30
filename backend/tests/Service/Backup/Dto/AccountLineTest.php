<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\AccountLine;
use App\Service\Reader\MagazineStyle;
use PHPUnit\Framework\TestCase;

final class AccountLineTest extends TestCase
{
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

    /**
     * A backup written before #541 has no `showReasons` key in its
     * recommendation settings. It must import as the default, false, rather
     * than being rejected as malformed.
     */
    public function testAnOldBackupWithoutShowReasonsImportsAsFalse(): void
    {
        $line = self::lineWithRecommendationSettings(self::recommendationSettingsWithoutOptionalFields());

        $account = AccountLine::fromLine($line);

        self::assertNotNull($account->recommendationSettings);
        self::assertFalse($account->recommendationSettings->showReasons);
    }

    public function testShowReasonsIsReadFromTheLine(): void
    {
        $settings = self::recommendationSettingsWithoutOptionalFields();
        $settings['showReasons'] = true;

        $account = AccountLine::fromLine(self::lineWithRecommendationSettings($settings));

        self::assertNotNull($account->recommendationSettings);
        self::assertTrue($account->recommendationSettings->showReasons);
    }

    /**
     * A backup written before #493 has no `profileText` key. It must import
     * as null — the reader's inferred preference profile simply was not
     * captured yet — rather than being rejected as malformed.
     */
    public function testTreatsAnAbsentPreferenceProfileAsNull(): void
    {
        $line = self::lineWithRecommendationSettings(self::recommendationSettingsWithoutOptionalFields());

        $account = AccountLine::fromLine($line);

        self::assertNotNull($account->recommendationSettings);
        self::assertNull($account->recommendationSettings->profileText);
    }

    public function testReadsTheStoredPreferenceProfile(): void
    {
        $settings = self::recommendationSettingsWithoutOptionalFields();
        $settings['profileText'] = 'Likes cartography.';

        $account = AccountLine::fromLine(self::lineWithRecommendationSettings($settings));

        self::assertNotNull($account->recommendationSettings);
        self::assertSame('Likes cartography.', $account->recommendationSettings->profileText);
    }

    /**
     * @param array<string, mixed> $recommendationSettings
     *
     * @return array<string, mixed>
     */
    private static function lineWithRecommendationSettings(array $recommendationSettings): array
    {
        return [
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'recommendationSettings' => $recommendationSettings,
        ];
    }

    /**
     * The minimal recommendation settings shape: no `showReasons` (#541) and
     * no `profileText` (#493), the two fields added to this object after its
     * original release. Tests build on this to prove each addition imports
     * as its default when the key is absent, and reads back correctly when
     * present.
     *
     * @return array<string, mixed>
     */
    private static function recommendationSettingsWithoutOptionalFields(): array
    {
        return [
            'guidancePrompt' => 'Only long reads, please.',
            'favoritesCap' => 11,
            'keptCap' => 12,
            'viewedCap' => 13,
            'candidatePoolSize' => 14,
            'lookbackDays' => 15,
            'picksLimit' => 16,
            'contextWindow' => 17000,
            'batchCount' => 3,
            'debugEnabled' => true,
            'autoGenerateIntervalHours' => 8,
        ];
    }
}
