<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

/**
 * The feed a platform's share link points at, resolved before anything is fetched. A null means "not my link" or
 * "could not resolve", and a link that is not this resolver's shape costs no fetch; discovery then continues with
 * the entered URL.
 */
interface ShareLinkFeedInterface
{
    public function feedUrl(string $enteredUrl): ?string;
}
