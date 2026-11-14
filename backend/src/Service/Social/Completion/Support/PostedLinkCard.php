<?php

declare(strict_types=1);

namespace App\Service\Completion\Support;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Share\Support\ShareTargets;

/**
 * A network's card filed under the link the entry shares. Mastodon builds it from the status's first link but names
 * it by the page's canonical URL, which can differ from the posted one.
 */
final class PostedLinkCard
{
    public static function of(Entry $entry, LinkCardContent $card): ?LinkCardContent
    {
        $targets = ShareTargets::uncardedOf($entry, []);
        if (\in_array($card->url, $targets, true)) {
            return $card;
        }
        if ($targets === []) {
            return null;
        }

        return StorableLinkCard::of(
            new LinkCardContent($targets[0], $card->title, $card->description, $card->imageUrl, $card->siteName),
        );
    }

    private function __construct()
    {
    }
}
