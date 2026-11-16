<?php

declare(strict_types=1);

namespace App\Service\Social\Social\TimelineSource;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\LemmyPostEntries;
use App\Service\Social\Social\Model\LemmyListing;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\Support\NetworkSearchQueries;
use App\Service\Social\Social\Support\SocialSearchPaths;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyTimelineSource implements TimelineSourceInterface
{
    public function __construct(
        private LemmyAccountApi $lemmy,
        private LemmyPostEntries $entries,
    ) {
    }

    public function requests(SocialFeed $socialFeed): array
    {
        $account = $socialFeed->getSocialAccount();

        return array_map(
            fn (string $path): ApiRequestModel => $this->lemmy->authorizedGet($account, $path),
            self::pathsOf($socialFeed),
        );
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

    /** @return non-empty-list<string> */
    private static function pathsOf(SocialFeed $socialFeed): array
    {
        $listing = LemmyListing::ofFeedKind($socialFeed->getKind());
        if (null !== $listing) {
            return ['/api/v3/post/list?type_=' . $listing->value . '&sort=New&limit=' . LemmyListing::PAGE_SIZE];
        }

        return SocialFeedKind::Search === $socialFeed->getKind()
            ? self::searchPathsOf($socialFeed)
            : throw new SocialNetworkUnreachableException('Lemmy has no such timeline.');
    }

    /** @return non-empty-list<string> */
    private static function searchPathsOf(SocialFeed $socialFeed): array
    {
        try {
            $queries = NetworkSearchQueries::of(SocialNetwork::Lemmy, $socialFeed->getRemoteId());
        } catch (SearchQueryTooBroadException $tooBroad) {
            throw new SocialNetworkUnreachableException($tooBroad->getMessage(), previous: $tooBroad);
        }

        return array_map(SocialSearchPaths::lemmy(...), $queries);
    }
}
