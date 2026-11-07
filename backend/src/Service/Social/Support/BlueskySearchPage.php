<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

/**
 * A search answer as entries: every post but a reply whose own text, or the post it quotes, matches the query; each is
 * read as a timeline item of its own.
 */
final class BlueskySearchPage
{
    private const string QUOTE = 'app.bsky.embed.record#view';
    private const string QUOTE_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';

    /** @throws SocialNetworkUnreachableException */
    public static function of(ApiResponseModel $accepted, string $accountDid, string $query): TimelinePageModel
    {
        $posts = $accepted->json()['posts'] ?? null;
        if (!\is_array($posts) || !array_is_list($posts)) {
            throw new SocialNetworkUnreachableException('Bluesky answered without search results.');
        }

        return BlueskyTimelinePage::of(self::itemsOf($posts, SocialSearchMatch::matcher($query)), $accountDid);
    }

    /**
     * @param list<mixed>               $posts
     * @param \Closure(string...): bool $matches
     *
     * @return list<JsonNodeModel>
     */
    private static function itemsOf(array $posts, \Closure $matches): array
    {
        $items = [];
        foreach ($posts as $post) {
            $node = JsonNodeModel::of($post);
            if (!$node->node('record')->has('reply') && $matches(...self::ownTextsOf($node))) {
                $items[] = JsonNodeModel::of(['post' => $post]);
            }
        }

        return $items;
    }

    /** @return list<string> */
    private static function ownTextsOf(JsonNodeModel $post): array
    {
        $embed = $post->node('embed');
        $quoted = match ($embed->type()) {
            self::QUOTE => $embed->node('record'),
            self::QUOTE_WITH_MEDIA => $embed->node('record')->node('record'),
            default => JsonNodeModel::of(null),
        };

        return [$post->node('record')->text('text') ?? '', $quoted->node('value')->text('text') ?? ''];
    }

    private function __construct()
    {
    }
}
