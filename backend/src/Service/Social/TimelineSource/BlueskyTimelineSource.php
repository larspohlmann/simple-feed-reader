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
use App\Service\Social\Support\BlueskySearchPage;
use App\Service\Social\Support\BlueskyTimelinePage;
use App\Service\Social\Support\SocialSearchPaths;

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

        $accepted = BlueskySessions::accepted($answer);

        return SocialFeedKind::Search === $socialFeed->getKind()
            ? BlueskySearchPage::of(
                $accepted,
                $socialFeed->getSocialAccount()->getIdentity(),
                $socialFeed->getRemoteId(),
            )
            : self::timelinePageOf($socialFeed, $accepted);
    }

    public function dropSession(SocialAccount $account): void
    {
        $this->sessions->forget($account);
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    private static function timelinePageOf(SocialFeed $socialFeed, ApiResponseModel $accepted): TimelinePageModel
    {
        $items = JsonNodeModel::of($accepted->json())->listAt('feed')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without a feed.');

        return SocialFeedKind::Author === $socialFeed->getKind()
            ? BlueskyTimelinePage::ofAuthor($items, $socialFeed->getRemoteId())
            : BlueskyTimelinePage::of($items, $socialFeed->getSocialAccount()->getIdentity());
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $remoteId = rawurlencode($socialFeed->getRemoteId());

        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => self::timelinePath('/xrpc/app.bsky.feed.getTimeline?'),
            SocialFeedKind::UserList => self::timelinePath('/xrpc/app.bsky.feed.getListFeed?list=' . $remoteId . '&'),
            SocialFeedKind::CustomFeed => self::timelinePath('/xrpc/app.bsky.feed.getFeed?feed=' . $remoteId . '&'),
            SocialFeedKind::Author => self::timelinePath(
                '/xrpc/app.bsky.feed.getAuthorFeed?actor=' . $remoteId . '&filter=posts_and_author_threads&',
            ),
            SocialFeedKind::Search => SocialSearchPaths::bluesky($socialFeed->getRemoteId()),
            SocialFeedKind::Tag,
            SocialFeedKind::Trending => throw new SocialNetworkUnreachableException('Bluesky has no such timeline.'),
        };
    }

    private static function timelinePath(string $pathAndQuery): string
    {
        return $pathAndQuery . 'limit=' . self::PAGE_SIZE;
    }
}
