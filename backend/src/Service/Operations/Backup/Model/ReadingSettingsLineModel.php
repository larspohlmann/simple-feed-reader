<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Model;

use App\Entity\ReadingSettings;
use App\Enum\Handedness;
use App\Enum\MagazineStyle;
use App\Enum\SidebarStyle;
use App\Enum\Theme;
use App\Service\Operations\Backup\Support\LineField;
use App\Service\Operations\Backup\Support\LineFieldWithDefault;

final readonly class ReadingSettingsLineModel
{
    public function __construct(
        public bool $scrapeFallbackEnabled,
        public MagazineStyle $magazineStyle,
        public SidebarStyle $sidebarStyle,
        public Theme $theme,
        public Handedness $handedness,
        public bool $trendingEnabled,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        // An unknown value restores its default.
        return new self(
            scrapeFallbackEnabled: LineField::bool($line, 'scrapeFallbackEnabled'),
            magazineStyle: MagazineStyle::tryFrom(
                LineFieldWithDefault::string($line, 'magazineStyle', MagazineStyle::Boxed->value),
            ) ?? MagazineStyle::Boxed,
            sidebarStyle: SidebarStyle::tryFrom(
                LineFieldWithDefault::string($line, 'sidebarStyle', SidebarStyle::Boxed->value),
            ) ?? SidebarStyle::Boxed,
            theme: Theme::tryFrom(
                LineFieldWithDefault::string($line, 'theme', Theme::Graphite->value),
            ) ?? Theme::Graphite,
            handedness: Handedness::tryFrom(
                LineFieldWithDefault::string($line, 'handedness', Handedness::Right->value),
            ) ?? Handedness::Right,
            trendingEnabled: LineFieldWithDefault::bool($line, 'trendingEnabled', true),
        );
    }

    public static function fromSettings(ReadingSettings $settings): self
    {
        return new self(
            scrapeFallbackEnabled: $settings->isScrapeFallbackEnabled(),
            magazineStyle: $settings->getMagazineStyle(),
            sidebarStyle: $settings->getSidebarStyle(),
            theme: $settings->getTheme(),
            handedness: $settings->getHandedness(),
            trendingEnabled: $settings->isTrendingEnabled(),
        );
    }
}
