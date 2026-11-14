<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Social\Bluesky\BlueskyEntryIdentityUrl;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Service\Foundation\Url\UrlNormalizer;

/** An EntryUrlHash over every identity URL the container tags: a test that writes url_hash hashes like production. */
final class EntryUrlHashes
{
    private function __construct()
    {
    }

    public static function production(): EntryUrlHash
    {
        return new EntryUrlHash(new UrlNormalizer(), [new BlueskyEntryIdentityUrl()]);
    }
}
