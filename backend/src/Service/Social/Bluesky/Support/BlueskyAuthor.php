<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\BlueskyPost\Support\BlueskyWeb;

final class BlueskyAuthor
{
    public static function name(JsonNodeModel $actor): ?string
    {
        return $actor->string('displayName') ?? $actor->string('handle');
    }

    public static function avatar(JsonNodeModel $actor): ?string
    {
        return AbsoluteHttpUrl::orNull($actor->string('avatar'));
    }

    public static function profile(JsonNodeModel $actor): ?string
    {
        $handleOrDid = $actor->string('handle') ?? $actor->string('did');

        return null === $handleOrDid ? null : BlueskyWeb::profileUrl($handleOrDid);
    }

    private function __construct()
    {
    }
}
