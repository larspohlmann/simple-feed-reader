<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Support;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;

/** What a profile depends on: the history's entry ids per section, the caps, and the connection and its model. */
final class ProfileInputFingerprint
{
    public static function of(
        RecommendationHistoryModel $history,
        RecommendationHistoryCaps $caps,
        AiProviderSettings $connection,
    ): string {
        return hash('sha256', json_encode([
            'favorites' => self::entryIds($history->favorites),
            'kept' => self::entryIds($history->kept),
            'viewed' => self::entryIds($history->viewed),
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

    private function __construct()
    {
    }
}
