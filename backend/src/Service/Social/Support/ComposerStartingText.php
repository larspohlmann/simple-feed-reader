<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SharedLinkModel;

/** Where the composer's text starts before the reader types. */
final class ComposerStartingText
{
    private function __construct()
    {
    }

    /** @param list<string> $mentions */
    public static function mentioning(array $mentions): string
    {
        return [] === $mentions ? '' : implode(' ', $mentions) . ' ';
    }

    /** A Bluesky link post carries its link in the card, so the text starts with the title alone. */
    public static function sharing(SocialNetwork $network, SharedLinkModel $link): string
    {
        return match (true) {
            SocialNetwork::Bluesky === $network => $link->title,
            '' === $link->title => $link->url,
            default => $link->title . "\n\n" . $link->url,
        };
    }
}
