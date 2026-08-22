<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\AccountLine;
use PHPUnit\Framework\TestCase;

final class AccountLineTest extends TestCase
{
    /**
     * A backup written before #541 has no `showReasons` key in its
     * recommendation settings. It must import as the default, false, rather
     * than being rejected as malformed.
     */
    public function testAnOldBackupWithoutShowReasonsImportsAsFalse(): void
    {
        $line = self::lineWithRecommendationSettings(self::recommendationSettingsWithoutShowReasons());

        $account = AccountLine::fromLine($line);

        self::assertNotNull($account->recommendationSettings);
        self::assertFalse($account->recommendationSettings->showReasons);
    }

    public function testShowReasonsIsReadFromTheLine(): void
    {
        $settings = self::recommendationSettingsWithoutShowReasons();
        $settings['showReasons'] = true;

        $account = AccountLine::fromLine(self::lineWithRecommendationSettings($settings));

        self::assertNotNull($account->recommendationSettings);
        self::assertTrue($account->recommendationSettings->showReasons);
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
     * @return array<string, mixed>
     */
    private static function recommendationSettingsWithoutShowReasons(): array
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
