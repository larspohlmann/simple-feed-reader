<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Http\FeedAnnotationVisibility;
use App\Http\RecommendationFeedJson;

/**
 * What JSON the for-you feed page returns for a user — paginates their
 * recommendation feed, then annotates each entry according to their
 * recommendation settings (#321). The reason and its score are one
 * explanation and follow one switch — the reader's "show reasons" preference.
 * Debug is deliberately not consulted here: it keeps the per-run call logs,
 * not a second way into the feed's annotations (#576).
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

        $visibility = new FeedAnnotationVisibility(
            showExplanation: $this->settings->forUser($user)->showReasons,
        );

        return RecommendationFeedJson::page($page->rows, $page->nextCursor, $visibility);
    }
}
