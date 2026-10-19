<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Service\Social\Support\PrivateFeedRules;

final readonly class MastodonTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 40;

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function request(SocialFeed $socialFeed): ApiRequestModel
    {
        return $this->mastodon->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed));
    }

    public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel
    {
        return self::pageFrom(MastodonApiClient::accepted(MastodonApiClient::answerOf($outcome)));
    }

    public function dropSession(SocialAccount $account): void
    {
    }

    private static function pageFrom(ApiResponseModel $accepted): TimelinePageModel
    {
        $statuses = JsonNodeModel::listOf(json_decode($accepted->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }

    private static function listId(SocialFeed $socialFeed): string
    {
        $listId = $socialFeed->getRemoteId();

        return PrivateFeedRules::isMastodonListId($listId)
            ? $listId
            : throw new SocialNetworkUnreachableException('The Mastodon list id is not a number.');
    }

    private static function tagName(SocialFeed $socialFeed): string
    {
        $tagName = $socialFeed->getRemoteId();

        return PrivateFeedRules::isMastodonTagName($tagName)
            ? $tagName
            : throw new SocialNetworkUnreachableException('The Mastodon tag name is not a hashtag.');
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => self::timelinePath($socialFeed, 'home'),
            SocialFeedKind::UserList => self::timelinePath($socialFeed, 'list/' . self::listId($socialFeed)),
            SocialFeedKind::Tag => self::timelinePath($socialFeed, 'tag/' . rawurlencode(self::tagName($socialFeed))),
            SocialFeedKind::Trending => '/api/v1/trends/statuses?limit=' . self::PAGE_SIZE,
            SocialFeedKind::Author, SocialFeedKind::CustomFeed => throw new SocialNetworkUnreachableException(
                'Mastodon has no such timeline.',
            ),
        };
    }

    private static function timelinePath(SocialFeed $socialFeed, string $timeline): string
    {
        $newestSeen = $socialFeed->getNewestSeenId();

        return '/api/v1/timelines/' . $timeline . '?limit=' . self::PAGE_SIZE
            . (null === $newestSeen ? '' : '&min_id=' . rawurlencode($newestSeen));
    }
}
