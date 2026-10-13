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

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $path = SocialFeedKind::UserList === $socialFeed->getKind()
            ? '/api/v1/timelines/list/' . self::listId($socialFeed)
            : '/api/v1/timelines/home';
        $newestSeen = $socialFeed->getNewestSeenId();

        return $path . '?limit=' . self::PAGE_SIZE
            . (null === $newestSeen ? '' : '&min_id=' . rawurlencode($newestSeen));
    }
}
