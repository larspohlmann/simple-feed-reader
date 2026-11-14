<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Url\EntryIdentityUrl\EntryIdentityUrlInterface;

/** The timeline links a post by DID, Bluesky's RSS by handle: the AT URI guid is what both share. */
final readonly class BlueskyEntryIdentityUrl implements EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string
    {
        return AtPostUri::webUrl($guid);
    }
}
