<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

/** A search answer as entries: every post but a reply, each read as a timeline item of its own. */
final class BlueskySearchPage
{
    /** @throws SocialNetworkUnreachableException */
    public static function of(ApiResponseModel $accepted, string $accountDid): TimelinePageModel
    {
        $posts = $accepted->json()['posts'] ?? null;
        if (!\is_array($posts) || !array_is_list($posts)) {
            throw new SocialNetworkUnreachableException('Bluesky answered without search results.');
        }

        return BlueskyTimelinePage::of(self::itemsOf($posts), $accountDid);
    }

    /**
     * @param list<mixed> $posts
     *
     * @return list<JsonNodeModel>
     */
    private static function itemsOf(array $posts): array
    {
        $items = [];
        foreach ($posts as $post) {
            if (!JsonNodeModel::of($post)->node('record')->has('reply')) {
                $items[] = JsonNodeModel::of(['post' => $post]);
            }
        }

        return $items;
    }

    private function __construct()
    {
    }
}
