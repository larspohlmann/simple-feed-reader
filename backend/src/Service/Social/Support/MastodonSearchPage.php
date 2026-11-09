<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SearchedPostModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Text\Support\PlainText;

/**
 * A search's answers, one per alternative, as entries: every status but a reply whose author and own text, or the
 * status it quotes, match the query.
 */
final class MastodonSearchPage
{
    /**
     * @param list<ApiResponseModel> $accepted one answer per alternative of the query
     *
     * @throws SocialNetworkUnreachableException
     */
    public static function of(array $accepted, string $query): TimelinePageModel
    {
        $matches = SocialSearchMatch::matcher($query);

        return MastodonTimelinePage::of(array_values(array_filter(
            self::distinctStatusesOf($accepted),
            static fn (JsonNodeModel $status): bool => null === $status->string('in_reply_to_id')
                && $matches(self::searchedPostOf(MastodonTimelinePage::originalOf($status))),
        )));
    }

    /**
     * @param list<ApiResponseModel> $accepted
     *
     * @return list<JsonNodeModel> each status once, however many alternatives found it
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function distinctStatusesOf(array $accepted): array
    {
        $statusesById = [];
        foreach ($accepted as $answer) {
            $statuses = JsonNodeModel::of($answer->json())->listAt('statuses')
                ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without search results.');
            foreach ($statuses as $status) {
                $statusesById[$status->string('id') ?? ''] ??= $status;
            }
        }

        return array_values($statusesById);
    }

    private static function searchedPostOf(JsonNodeModel $post): SearchedPostModel
    {
        $account = $post->node('account');

        return new SearchedPostModel(
            array_values(array_filter([$account->string('acct'), MastodonAuthor::handle($account)], \is_string(...))),
            self::ownTextsOf($post),
        );
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
