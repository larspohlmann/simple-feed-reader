<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Support;

use App\Entity\InclusionSwitches;
use App\Enum\InclusionSurface;

/** The surface switches a subscription or tag line carries; a backup older than Your stream seeds it from For you. */
final class BackupInclusions
{
    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): InclusionSwitches
    {
        $forYou = LineFieldWithDefault::bool($line, 'includeInForYou', true);

        return new InclusionSwitches(
            allItems: LineFieldWithDefault::bool($line, 'includeInAllItems', true),
            forYou: $forYou,
            savedSearches: LineFieldWithDefault::bool($line, 'includeInSavedSearches', true),
            trending: LineFieldWithDefault::bool($line, 'includeInTrending', true),
            yourStream: LineFieldWithDefault::bool($line, 'includeInYourStream', $forYou),
        );
    }

    /**
     * @return array{
     *   includeInAllItems: bool, includeInForYou: bool, includeInSavedSearches: bool, includeInTrending: bool,
     *   includeInYourStream: bool
     * }
     */
    public static function toFields(InclusionSwitches $switches): array
    {
        return [
            'includeInAllItems' => $switches->isIncludedIn(InclusionSurface::AllItems),
            'includeInForYou' => $switches->isIncludedIn(InclusionSurface::ForYou),
            'includeInSavedSearches' => $switches->isIncludedIn(InclusionSurface::SavedSearches),
            'includeInTrending' => $switches->isIncludedIn(InclusionSurface::Trending),
            'includeInYourStream' => $switches->isIncludedIn(InclusionSurface::YourStream),
        ];
    }

    private function __construct()
    {
    }
}
