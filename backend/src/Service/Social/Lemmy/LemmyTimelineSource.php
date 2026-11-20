<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyApiClient;
use App\Service\Social\Lemmy\LemmyPostEntries;
use App\Service\Social\Lemmy\Model\LemmyListing;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\TimelineSource\TimelineSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyTimelineSource implements TimelineSourceInterface
{
    public function __construct(
        private LemmyAccountApi $lemmy,
        private LemmyPostEntries $entries,
        private LemmySearchSource $search,
    ) {
    }

    public function requests(SocialFeed $socialFeed): array
    {
        $account = $socialFeed->getSocialAccount();
        if (SocialFeedKind::Search === $socialFeed->getKind()) {
            return $this->search->buildRequests($account, $socialFeed->getRemoteId());
        }

        return [$this->lemmy->authorizedGet($account, self::buildListingPath($socialFeed))];
    }

    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel
    {
        return LemmyApiClient::acceptedInRound(LemmyApiClient::requireAnswer($outcome));
    }

    public function buildPage(SocialFeed $socialFeed, array $accepted): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        if (SocialFeedKind::Search === $socialFeed->getKind()) {
            return $this->search->buildPage($account, $accepted, $socialFeed->getRemoteId());
        }
        $answer = $accepted[0] ?? throw new \LogicException('A Lemmy timeline is read with one request.');

        return $this->entries->page($account, $answer);
    }

    public function dropSession(SocialAccount $account): void
    {
    }

    private static function buildListingPath(SocialFeed $socialFeed): string
    {
        $listing = LemmyListing::fromFeedKind($socialFeed->getKind())
            ?? throw new SocialNetworkUnreachableException('Lemmy has no such timeline.');

        return '/api/v3/post/list?type_=' . $listing->value . '&sort=New&limit=' . LemmyListing::PAGE_SIZE;
    }
}
