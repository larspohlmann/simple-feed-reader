<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\BlueskyWeb;
use App\Service\Url\Support\AbsoluteHttpUrl;

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
