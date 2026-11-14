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
use App\Service\Social\Social\Support\SocialSearchPaths;
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
        return [$this->lemmy->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed))];
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

    private static function pathOf(SocialFeed $socialFeed): string
    {
        return match ($socialFeed->getKind()) {
            SocialFeedKind::Home => self::listingPath('Subscribed'),
            SocialFeedKind::Local => self::listingPath('Local'),
            SocialFeedKind::All => self::listingPath('All'),
            SocialFeedKind::Search => SocialSearchPaths::lemmy($socialFeed->getRemoteId()),
            SocialFeedKind::UserList,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Author,
            SocialFeedKind::Tag,
            SocialFeedKind::Trending => throw new SocialNetworkUnreachableException('Lemmy has no such timeline.'),
        };
    }

    private static function listingPath(string $listing): string
    {
        return '/api/v3/post/list?type_=' . $listing . '&sort=New&limit=' . self::PAGE_SIZE;
    }
}
