<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Http\FeedAnnotationVisibility;
use App\Http\RecommendationFeedJson;

/**
 * What JSON the for-you feed page returns for a user — paginates their
 * recommendation feed, then annotates each entry according to their
 * recommendation settings (#321).
 */
final readonly class ForYouFeedResponder
{
    public function __construct(
        private RecommendationFeedPager $pager,
        private RecommendationSettingsResolver $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function page(User $user, ?string $cursor, int $limit): array
    {
        $page = $this->pager->page((int) $user->getId(), $cursor, $limit);

        $debugEnabled = $this->settings->forUser($user)->debugEnabled;
        $visibility = new FeedAnnotationVisibility(showReasons: $debugEnabled, showScores: $debugEnabled);

        return RecommendationFeedJson::page($page->rows, $page->nextCursor, $visibility);
    }
}
