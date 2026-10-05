<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Support;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;

/**
 * What a profile depends on: the history's entry ids per section, the saved-search terms, the caps,
 * and the connection and its model.
 */
final class ProfileInputFingerprint
{
    public static function of(
        ProfileInputsModel $inputs,
        RecommendationHistoryCaps $caps,
        AiProviderSettings $connection,
    ): string {
        return hash('sha256', json_encode([
            'favorites' => self::entryIds($inputs->history->favorites),
            'kept' => self::entryIds($inputs->history->kept),
            'viewed' => self::entryIds($inputs->history->viewed),
            'savedSearches' => self::sorted($inputs->savedSearchTerms),
            'caps' => [$caps->favorites, $caps->kept, $caps->viewed],
            'connection' => $connection->getId(),
            'model' => $connection->getModel(),
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<ArticleLineModel> $lines
     *
     * @return list<int>
     */
    private static function entryIds(array $lines): array
    {
        return array_map(static fn (ArticleLineModel $line): int => $line->entryId, $lines);
    }

    /**
     * @param list<string> $savedSearchTerms
     *
     * @return list<string>
     */
    private static function sorted(array $savedSearchTerms): array
    {
        sort($savedSearchTerms, \SORT_STRING);

        return $savedSearchTerms;
    }

    private function __construct()
    {
    }
}
