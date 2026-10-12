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
use App\Service\Social\Support\PrivateFeedRules;

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
        $statuses = JsonNodeModel::listOf(json_decode($answer->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }

    private static function listId(SocialFeed $socialFeed): string
    {
        $listId = $socialFeed->getRemoteId() ?? '';

        return 1 === preg_match(PrivateFeedRules::MASTODON_LIST, $listId)
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
