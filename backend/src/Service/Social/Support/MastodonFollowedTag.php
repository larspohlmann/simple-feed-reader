<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\SocialCollectionModel;

final class MastodonFollowedTag
{
    public static function collectionOf(JsonNodeModel $tag): ?SocialCollectionModel
    {
        $tagName = $tag->string('name');
        if (null === $tagName || !PrivateFeedRules::isMastodonTagName($tagName)) {
            return null;
        }

        return new SocialCollectionModel($tagName, '#' . $tagName);
    }

    private function __construct()
    {
    }
}
