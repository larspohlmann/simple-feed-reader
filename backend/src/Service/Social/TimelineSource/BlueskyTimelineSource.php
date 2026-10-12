<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Support\RetryAfterHeader;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\BlueskyTimelinePage;
use Symfony\Component\HttpFoundation\Response;

/** The newest page only: posts already stored are recognised by their guid, which is the newest-seen cutoff. */
final readonly class BlueskyTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 50;
    private const array STALE_SESSION_ERRORS = ['ExpiredToken', 'InvalidToken'];
    private const array STALE_SESSION_STATUSES = [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED];

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskySessions $sessions,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        $answer = $this->ask($socialFeed, $this->sessions->current($account));
        if (self::isStaleSession($answer)) {
            $answer = $this->ask($socialFeed, $this->sessions->fresh($account));
        }

        return BlueskyTimelinePage::of(self::itemsOf($answer), $account->getIdentity());
    }

    private function ask(SocialFeed $socialFeed, BlueskySessionModel $session): ApiResponseModel
    {
        return $this->bluesky->answer(ApiRequestModel::get(
            $session->pdsUrl . self::pathOf($socialFeed),
            ['Authorization' => 'Bearer ' . $session->accessJwt],
        ));
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $remoteId = rawurlencode($socialFeed->getRemoteId() ?? '');

        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => '/xrpc/app.bsky.feed.getTimeline?limit=' . self::PAGE_SIZE,
            SocialFeedKind::UserList
                => '/xrpc/app.bsky.feed.getListFeed?list=' . $remoteId . '&limit=' . self::PAGE_SIZE,
            SocialFeedKind::CustomFeed => '/xrpc/app.bsky.feed.getFeed?feed=' . $remoteId . '&limit=' . self::PAGE_SIZE,
        };
    }

    private static function isStaleSession(ApiResponseModel $answer): bool
    {
        return \in_array($answer->status, self::STALE_SESSION_STATUSES, true)
            && \in_array(JsonNodeModel::of($answer->json())->string('error'), self::STALE_SESSION_ERRORS, true);
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
        if (Response::HTTP_TOO_MANY_REQUESTS === $answer->status) {
            throw new SocialNetworkThrottledException(
                'Bluesky asked for fewer requests.',
                RetryAfterHeader::seconds($answer->header('retry-after')),
            );
        }
        if (Response::HTTP_UNAUTHORIZED === $answer->status || self::isStaleSession($answer)) {
            throw new SocialCredentialsRejectedException('Bluesky refused a fresh session for this account.');
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('Bluesky answered %d.', $answer->status));
        }

        return JsonNodeModel::of($answer->json())->listAt('feed')
            ?? throw new SocialNetworkUnreachableException('Bluesky answered without a feed.');
    }
}
