<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

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
