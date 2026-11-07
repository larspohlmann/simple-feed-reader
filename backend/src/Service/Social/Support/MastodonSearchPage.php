<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Text\Support\PlainText;

/** A search answer as entries: every status but a reply whose own text, or the status it quotes, matches the query. */
final class MastodonSearchPage
{
    /** @throws SocialNetworkUnreachableException */
    public static function of(ApiResponseModel $accepted, string $query): TimelinePageModel
    {
        $statuses = JsonNodeModel::of($accepted->json())->listAt('statuses')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without search results.');
        $matches = SocialSearchMatch::matcher($query);

        return MastodonTimelinePage::of(array_values(array_filter(
            $statuses,
            static fn (JsonNodeModel $status): bool => null === $status->string('in_reply_to_id')
                && $matches(...self::ownTextsOf(MastodonTimelinePage::originalOf($status))),
        )));
    }

    /** @return list<string> */
    private static function ownTextsOf(JsonNodeModel $post): array
    {
        return [
            PlainText::fromHtmlBlocks($post->string('content')) ?? '',
            PlainText::fromHtmlBlocks($post->node('quote')->node('quoted_status')->string('content')) ?? '',
        ];
    }

    private function __construct()
    {
    }
}
