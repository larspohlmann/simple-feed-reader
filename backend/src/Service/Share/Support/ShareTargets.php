<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Entity\Entry;

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
            return \in_array($target, $cardedUrls, true) ? [] : [$target];
        }

        return SharedLinks::of((string) $entry->getContentHtml())->uncardedBy($cardedUrls);
    }

    private function __construct()
    {
    }
}
