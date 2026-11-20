<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Support;

use App\Entity\Entry;
use App\Service\Social\Share\Model\ShareFetchPlanModel;
use App\Service\Social\Share\Support\TitledShareTarget;

/**
 * The planned links a stored shared link may stand in for: none of a titled share, whose page may declare the medium
 * that makes it a media share, and none that a post's "RE:" line quotes, which is read as a quoted post.
 */
final class ReusableShareUrls
{
    /** @return list<string> */
    public static function filterUrls(Entry $entry, ShareFetchPlanModel $plan): array
    {
        if (TitledShareTarget::detectTarget($entry) !== null) {
            return [];
        }
        $quoted = QuoteLine::parseTrackingFreeTarget((string) $entry->getText()->getContentHtml());

        return array_values(array_filter($plan->urls, static fn (string $url): bool => $url !== $quoted));
    }

    private function __construct()
    {
    }
}
