<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\UrlOrigin;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Model\FollowedAccountModel;

final class LemmyCommunity
{
    /** @return FollowedAccountModel|null null when the view names no community on an https instance */
    public static function follow(JsonNodeModel $communityView): ?FollowedAccountModel
    {
        $community = $communityView->node('community');
        $identity = self::identityOf($community);
        if (null === $identity) {
            return null;
        }
        [$lowerName, $lowerHost] = explode('@', $identity, 2);

        return new FollowedAccountModel(
            $identity,
            '!' . $identity,
            $community->string('title'),
            LemmyProfileFeed::buildUrl($lowerName, $lowerHost),
            $community->string('icon'),
        );
    }

    /** @return string|null the lower-cased `name@host`, null when the community is not on an https instance */
    public static function identityOf(JsonNodeModel $community): ?string
    {
        $name = $community->string('name');
        $host = self::httpsHostOf($community->string('actor_id') ?? '');
        if (null === $name || null === $host) {
            return null;
        }

        return strtolower($name) . '@' . strtolower($host);
    }

    private static function httpsHostOf(string $actorId): ?string
    {
        $httpsActorId = AbsoluteHttpUrl::httpsOrNull($actorId);

        return null === $httpsActorId ? null : UrlOrigin::hostWithPort($httpsActorId);
    }

    private function __construct()
    {
    }
}
