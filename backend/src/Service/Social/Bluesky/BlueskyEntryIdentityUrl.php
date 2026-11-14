<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Foundation\Url\EntryIdentityUrl\EntryIdentityUrlInterface;

/** The timeline links a post by DID, Bluesky's RSS by handle: the AT URI guid is what both share. */
final readonly class BlueskyEntryIdentityUrl implements EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string
    {
        return AtPostUri::webUrl($guid);
    }
}
