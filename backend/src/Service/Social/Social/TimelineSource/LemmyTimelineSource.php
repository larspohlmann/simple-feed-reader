<?php

declare(strict_types=1);

namespace App\Service\Social\Social\TimelineSource;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\LemmyPostEntries;
use App\Service\Social\Social\Model\TimelinePageModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyTimelineSource implements TimelineSourceInterface
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private LemmyAccountApi $lemmy,
        private LemmyPostEntries $entries,
    ) {
    }

    public function requests(SocialFeed $socialFeed): array
    {
        return [$this->lemmy->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed->getKind()))];
    }

    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel
    {
        return LemmyApiClient::acceptedInRound(LemmyApiClient::answerOf($outcome));
    }

    public function pageOf(SocialFeed $socialFeed, array $accepted): TimelinePageModel
    {
        $answer = $accepted[0] ?? throw new \LogicException('A Lemmy timeline is read with one request.');

        return $this->entries->page($socialFeed->getSocialAccount(), $answer);
    }

    public function dropSession(SocialAccount $account): void
    {
    }

    private static function pathOf(SocialFeedKind $kind): string
    {
        return '/api/v3/post/list?type_=' . self::listingOf($kind) . '&sort=New&limit=' . self::PAGE_SIZE;
    }

    private static function listingOf(SocialFeedKind $kind): string
    {
        return match ($kind) {
            SocialFeedKind::Home => 'Subscribed',
            SocialFeedKind::Local => 'Local',
            SocialFeedKind::All => 'All',
            SocialFeedKind::UserList,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Author,
            SocialFeedKind::Tag,
            SocialFeedKind::Trending,
            SocialFeedKind::Search => throw new SocialNetworkUnreachableException('Lemmy has no such timeline.'),
        };
    }
}
