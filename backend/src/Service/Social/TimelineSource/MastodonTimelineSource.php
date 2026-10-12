<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\MastodonSessionModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\StoredCredential;
use App\Service\Social\Support\MastodonTimelinePage;

final readonly class MastodonTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 40;

    public function __construct(
        private MastodonApiClient $mastodon,
        private StoredCredential $storedCredential,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        $session = new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
        $answer = $this->mastodon->send($session->get($session->url(self::pathOf($socialFeed))));
        $statuses = JsonNodeModel::listOf($answer->json())
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }

    private static function pathOf(SocialFeed $socialFeed): string
    {
        $path = SocialFeedKind::UserList === $socialFeed->getKind()
            ? '/api/v1/timelines/list/' . rawurlencode($socialFeed->getRemoteId() ?? '')
            : '/api/v1/timelines/home';
        $newestSeen = $socialFeed->getNewestSeenId();

        return $path . '?limit=' . self::PAGE_SIZE
            . (null === $newestSeen ? '' : '&min_id=' . rawurlencode($newestSeen));
    }
}
