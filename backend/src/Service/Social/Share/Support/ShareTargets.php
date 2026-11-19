<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Support;

use App\Entity\Entry;
use App\Enum\ShareKind;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Social\Share\Model\ShareFetchPlanModel;

/** The pages an entry's cards come from: a titled share's one target, or the links a post's text shares. */
final class ShareTargets
{
    /**
     * The entry's targets without a card, in its order, and the posted form of each to check.
     *
     * @param list<string> $cardedUrls
     */
    public static function planOf(Entry $entry, array $cardedUrls): ShareFetchPlanModel
    {
        $target = TitledShareTarget::of($entry);
        if ($target !== null) {
            $carded = \in_array(TrackingFreeUrl::of($target), array_map(TrackingFreeUrl::of(...), $cardedUrls), true);

            return new ShareFetchPlanModel($carded ? [] : [$target]);
        }

        $shared = SharedLinks::of((string) $entry->getText()->getContentHtml());
        $urls = $shared->uncardedBy($cardedUrls);

        return new ShareFetchPlanModel($urls, array_intersect_key($shared->postedForms, array_flip($urls)));
    }

    public static function isCardableShare(Entry $entry): bool
    {
        return \in_array($entry->getShareKind(), [ShareKind::Link, ShareKind::Quote], true);
    }

    private function __construct()
    {
    }
}
