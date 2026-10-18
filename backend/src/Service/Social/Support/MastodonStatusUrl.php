<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** Where a Mastodon status is read on the web: its `url`, else its `uri` when that is a web address. */
final class MastodonStatusUrl
{
    public static function of(JsonNodeModel $status): ?string
    {
        return AbsoluteHttpUrl::orNull($status->string('url')) ?? AbsoluteHttpUrl::orNull($status->string('uri'));
    }

    private function __construct()
    {
    }
}
