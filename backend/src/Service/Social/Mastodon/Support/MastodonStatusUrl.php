<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

/** Where a Mastodon status is read on the web: its `url`, else its `uri` when that is a web address. */
final class MastodonStatusUrl
{
    public static function resolveUrl(JsonNodeModel $status): ?string
    {
        return AbsoluteHttpUrl::orNull($status->string('url')) ?? AbsoluteHttpUrl::orNull($status->string('uri'));
    }

    private function __construct()
    {
    }
}
