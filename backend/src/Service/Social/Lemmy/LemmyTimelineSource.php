<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyApiClient;
use App\Service\Social\Lemmy\LemmyPostEntries;
use App\Service\Social\Lemmy\LemmyPrivateFeedRule;
use App\Service\Social\Lemmy\Model\LemmyListing;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
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
        private LemmyPrivateFeedRule $privateFeedRule,
    ) {
    }

    public function requests(SocialFeed $socialFeed): array
    {
        $account = $socialFeed->getSocialAccount();
        if (SocialFeedKind::Search === $socialFeed->getKind()) {
            return array_map(
                fn (string $term): ApiRequestModel => $this->lemmy->searchGet($account, $term),
                $this->searchTermsOf($socialFeed),
            );
        }

        return [$this->lemmy->authorizedGet($account, self::listingPathOf($socialFeed))];
    }

    public function accepted(SocialFeed $socialFeed, FetchOutcomeModel $outcome): ApiResponseModel
    {
        return LemmyApiClient::acceptedInRound(LemmyApiClient::answerOf($outcome));
    }

    public function pageOf(SocialFeed $socialFeed, array $accepted): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        if (SocialFeedKind::Search === $socialFeed->getKind()) {
            return $this->entries->searchPage($account, $accepted, $socialFeed->getRemoteId());
        }
        $answer = $accepted[0] ?? throw new \LogicException('A Lemmy timeline is read with one request.');

        return $this->entries->page($account, $answer);
    }

    public function dropSession(SocialAccount $account): void
    {
    }

    private static function listingPathOf(SocialFeed $socialFeed): string
    {
        $listing = LemmyListing::ofFeedKind($socialFeed->getKind())
            ?? throw new SocialNetworkUnreachableException('Lemmy has no such timeline.');

        return '/api/v3/post/list?type_=' . $listing->value . '&sort=New&limit=' . LemmyListing::PAGE_SIZE;
    }

    /** @return non-empty-list<string> */
    private function searchTermsOf(SocialFeed $socialFeed): array
    {
        try {
            return $this->privateFeedRule->buildSearchQueries($socialFeed->getRemoteId());
        } catch (SearchQueryTooBroadException $tooBroad) {
            throw new SocialNetworkUnreachableException($tooBroad->getMessage(), previous: $tooBroad);
        }
    }
}
