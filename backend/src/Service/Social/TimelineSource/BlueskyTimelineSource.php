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
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\BlueskyTimelinePage;

final readonly class BlueskyTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 50;

    public function __construct(private BlueskySessions $sessions)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        $answer = $this->sessions->askAs($account, self::pathOf($socialFeed));

        return BlueskyTimelinePage::of(self::itemsOf($answer), $account->getIdentity());
    }

    public function request(SocialFeed $socialFeed): ApiRequestModel
    {
        return $this->sessions->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed));
    }

    public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel
    {
        $answer = BlueskyApiClient::answerOf($outcome);
        if (BlueskySessions::isStaleSession($answer)) {
            throw new SocialSessionStaleException(BlueskySessions::SESSION_REFUSED);
        }

        return BlueskyTimelinePage::of(self::itemsOf($answer), $socialFeed->getSocialAccount()->getIdentity());
    }

    public function dropSession(SocialAccount $account): void
    {
        $this->sessions->forget($account);
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $remoteId = rawurlencode($socialFeed->getRemoteId());

        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => '/xrpc/app.bsky.feed.getTimeline?',
            SocialFeedKind::UserList => '/xrpc/app.bsky.feed.getListFeed?list=' . $remoteId . '&',
            SocialFeedKind::CustomFeed => '/xrpc/app.bsky.feed.getFeed?feed=' . $remoteId . '&',
        } . 'limit=' . self::PAGE_SIZE;
    }

    /**
     * @return list<JsonNodeModel>
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    private static function itemsOf(ApiResponseModel $answer): array
    {
        return JsonNodeModel::of(BlueskySessions::accepted($answer)->json())->listAt('feed')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without a feed.');
    }
}
