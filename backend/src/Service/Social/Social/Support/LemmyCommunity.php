<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Url\Support\UrlOrigin;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Model\FollowedAccountModel;

final class LemmyCommunity
{
    /** @return FollowedAccountModel|null null when the view names no community on an https instance */
    public static function follow(JsonNodeModel $communityView): ?FollowedAccountModel
    {
        $community = $communityView->node('community');
        $name = $community->string('name');
        $host = self::httpsHostOf($community->string('actor_id') ?? '');
        if (null === $name || null === $host) {
            return null;
        }
        $lowerName = strtolower($name);
        $lowerHost = strtolower($host);
        $identity = $lowerName . '@' . $lowerHost;

        return new FollowedAccountModel(
            $identity,
            '!' . $identity,
            $community->string('title'),
            ProfileFeedUrl::lemmy($lowerName, $lowerHost),
            $community->string('icon'),
        );
    }

    private static function httpsHostOf(string $actorId): ?string
    {
        return 'https' === parse_url($actorId, \PHP_URL_SCHEME) ? UrlOrigin::hostWithPort($actorId) : null;
    }

    private function __construct()
    {
    }
}
