<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\Support\SocialSearchMatch;

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
    public static function fromAnswers(array $accepted, string $query): TimelinePageModel
    {
        $matches = SocialSearchMatch::matcher($query);

        return MastodonTimelinePage::fromStatuses(array_values(array_filter(
            self::collectDistinctStatuses($accepted),
            static fn (JsonNodeModel $status): bool => null === $status->string('in_reply_to_id')
                && $matches(self::buildSearchedPost(MastodonTimelinePage::resolveOriginal($status))),
        )));
    }

    /**
     * @param list<ApiResponseModel> $accepted
     *
     * @return list<JsonNodeModel> each status once, however many alternatives found it, newest first
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function collectDistinctStatuses(array $accepted): array
    {
        $statusesById = [];
        foreach ($accepted as $answer) {
            $statuses = JsonNodeModel::fromValue($answer->json())->listAt('statuses')
                ?? throw new SocialNetworkUnreachableException('The Mastodon instance gave no search results.');
            foreach ($statuses as $status) {
                $statusesById[$status->string('id') ?? ''] ??= $status;
            }
        }

        $statuses = array_values($statusesById);
        usort($statuses, static fn (JsonNodeModel $left, JsonNodeModel $right): int => MastodonTimelinePage::compareIds(
            $right->string('id') ?? '',
            $left->string('id') ?? '',
        ));

        return $statuses;
    }

    private static function buildSearchedPost(JsonNodeModel $post): SearchedPostModel
    {
        $account = $post->node('account');

        return new SearchedPostModel(
            array_values(array_filter([$account->string('acct'), MastodonAuthor::handle($account)], \is_string(...))),
            self::buildSearchTexts($post),
        );
    }

    /** @return list<string> */
    private static function buildSearchTexts(JsonNodeModel $post): array
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
