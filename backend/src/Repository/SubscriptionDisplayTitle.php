<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The single definition of a subscription's display title: its custom
 * override, else the feed title, else the bare feed URL as a last resort.
 * Extracted from EntryRepository::rowTitle so RecommendationItemRepository's
 * for-you feed projection and RecommendationCandidateLoader's prompt lines
 * fold the same rule without duplicating it a third time (#308 final review,
 * Important 3) -- same shape as EffectiveReadState, extracted for the same
 * reason.
 */
final class SubscriptionDisplayTitle
{
    public static function from(?string $customTitle, ?string $feedTitle, string $feedUrl): string
    {
        if (null !== $customTitle && '' !== $customTitle) {
            return $customTitle;
        }

        if (null !== $feedTitle && '' !== $feedTitle) {
            return $feedTitle;
        }

        return $feedUrl;
    }
}
