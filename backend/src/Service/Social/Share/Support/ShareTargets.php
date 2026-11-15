<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Support;

use App\Entity\Entry;
use App\Enum\ShareKind;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;

/** The pages an entry's cards come from: a titled share's one target, or the links a post's text shares. */
final class ShareTargets
{
    /**
     * @param list<string> $cardedUrls
     *
     * @return list<string> in the entry's order, without those already carded
     */
    public static function uncardedOf(Entry $entry, array $cardedUrls): array
    {
        $target = TitledShareTarget::of($entry);
        if ($target !== null) {
            return \in_array($target, array_map(TrackingFreeUrl::of(...), $cardedUrls), true) ? [] : [$target];
        }

        return SharedLinks::of((string) $entry->getText()->getContentHtml())->uncardedBy($cardedUrls);
    }

    /**
     * @param list<string> $urls tracking-free targets
     *
     * @return array<string, string> the href each was posted as, for those posted with tracking keys
     */
    public static function postedFormsOf(Entry $entry, array $urls): array
    {
        if (TitledShareTarget::of($entry) !== null) {
            return [];
        }

        return array_intersect_key(
            SharedLinks::of((string) $entry->getText()->getContentHtml())->postedForms,
            array_flip($urls),
        );
    }

    public static function isCardableShare(Entry $entry): bool
    {
        return \in_array($entry->getShareKind(), [ShareKind::Link, ShareKind::Quote], true);
    }

    private function __construct()
    {
    }
}
