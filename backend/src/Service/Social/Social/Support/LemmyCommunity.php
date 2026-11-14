<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Model\FollowedAccountModel;

final class LemmyCommunity
{
    /** @return FollowedAccountModel|null null when the view names no community on an https instance */
    public static function follow(JsonNodeModel $communityView): ?FollowedAccountModel
    {
        $community = $communityView->node('community');
        $name = $community->string('name');
        $parts = parse_url($community->string('actor_id') ?? '');
        $host = \is_array($parts) && 'https' === ($parts['scheme'] ?? null) ? ($parts['host'] ?? null) : null;
        if (null === $name || null === $host) {
            return null;
        }
        $identity = strtolower($name . '@' . $host);
        [$lowerName, $lowerHost] = explode('@', $identity);

        return new FollowedAccountModel(
            $identity,
            '!' . $identity,
            $community->string('title'),
            ProfileFeedUrl::lemmy($lowerName, $lowerHost),
            $community->string('icon'),
        );
    }

    private function __construct()
    {
    }
}
