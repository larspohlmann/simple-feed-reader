<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Reader\MagazineStyle;
use App\Service\Recommendation\RecommendationSettingsValues;

/**
 * The account's own settings, exactly once per backup.
 */
final readonly class AccountLine
{
    public function __construct(
        public string $locale,
        public bool $scrapeFallbackEnabled,
        public MagazineStyle $magazineStyle,
        public ?RecommendationSettingsValues $recommendationSettings,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            locale: LineField::string($line, 'locale'),
            scrapeFallbackEnabled: LineField::bool($line, 'scrapeFallbackEnabled'),
            magazineStyle: LineFieldWithDefault::enum($line, 'magazineStyle', MagazineStyle::Boxed),
            recommendationSettings: self::recommendationSettingsFromLine($line),
        );
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function recommendationSettingsFromLine(array $line): ?RecommendationSettingsValues
    {
        $settings = LineField::objectOrNull($line, 'recommendationSettings');
        if (null === $settings) {
            return null;
        }

        return new RecommendationSettingsValues(
            guidancePrompt: LineField::stringOrNull($settings, 'guidancePrompt'),
            profileText: LineField::stringOrNull($settings, 'profileText'),
            favoritesCap: LineField::int($settings, 'favoritesCap'),
            keptCap: LineField::int($settings, 'keptCap'),
            viewedCap: LineField::int($settings, 'viewedCap'),
            candidatePoolSize: LineField::int($settings, 'candidatePoolSize'),
            lookbackDays: LineField::int($settings, 'lookbackDays'),
            picksLimit: LineField::int($settings, 'picksLimit'),
            contextWindow: LineField::intOrNull($settings, 'contextWindow'),
            batchCount: LineField::intOrNull($settings, 'batchCount'),
            debugEnabled: LineField::bool($settings, 'debugEnabled'),
            autoGenerateIntervalHours: LineField::intOrNull($settings, 'autoGenerateIntervalHours'),
            showReasons: LineFieldWithDefault::bool($settings, 'showReasons', false),
        );
    }
}
