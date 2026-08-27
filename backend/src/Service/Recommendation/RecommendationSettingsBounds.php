<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The expert-settings limits, shared by the request validator and the JSON
 * response. The client reads the response, so this is the only source that
 * can change a limit.
 */
final class RecommendationSettingsBounds
{
    public const int FAVORITES_CAP_MINIMUM = 0;
    public const int FAVORITES_CAP_MAXIMUM = 500;
    public const int KEPT_CAP_MINIMUM = 0;
    public const int KEPT_CAP_MAXIMUM = 500;
    public const int VIEWED_CAP_MINIMUM = 0;
    public const int VIEWED_CAP_MAXIMUM = 500;
    public const int CANDIDATE_POOL_SIZE_MINIMUM = 10;
    public const int CANDIDATE_POOL_SIZE_MAXIMUM = 5000;
    public const int PICKS_LIMIT_MINIMUM = 1;
    public const int PICKS_LIMIT_MAXIMUM = 500;
    public const int BATCH_COUNT_MINIMUM = 1;
    public const int BATCH_COUNT_MAXIMUM = 100;
    public const int CONTEXT_WINDOW_MINIMUM = 4096;
    public const int CONTEXT_WINDOW_MAXIMUM = 2097152;

    /** @var array<string, array{min: int, max: int}> */
    public const array EXPERT_FIELDS = [
        'favoritesCap' => ['min' => self::FAVORITES_CAP_MINIMUM, 'max' => self::FAVORITES_CAP_MAXIMUM],
        'keptCap' => ['min' => self::KEPT_CAP_MINIMUM, 'max' => self::KEPT_CAP_MAXIMUM],
        'viewedCap' => ['min' => self::VIEWED_CAP_MINIMUM, 'max' => self::VIEWED_CAP_MAXIMUM],
        'candidatePoolSize' => [
            'min' => self::CANDIDATE_POOL_SIZE_MINIMUM,
            'max' => self::CANDIDATE_POOL_SIZE_MAXIMUM,
        ],
        'picksLimit' => ['min' => self::PICKS_LIMIT_MINIMUM, 'max' => self::PICKS_LIMIT_MAXIMUM],
        'batchCount' => ['min' => self::BATCH_COUNT_MINIMUM, 'max' => self::BATCH_COUNT_MAXIMUM],
        'contextWindow' => [
            'min' => self::CONTEXT_WINDOW_MINIMUM,
            'max' => self::CONTEXT_WINDOW_MAXIMUM,
        ],
    ];
}
