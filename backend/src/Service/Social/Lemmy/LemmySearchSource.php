<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyApiClient;
use App\Service\Social\Lemmy\LemmyPostEntries;
use App\Service\Social\Lemmy\LemmyPrivateFeedRule;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\SearchAnswers;
use App\Service\Social\Social\SearchSource\SearchSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmySearchSource implements SearchSourceInterface
{
    public function __construct(
        private LemmyAccountApi $lemmy,
        private LemmyPostEntries $entries,
        private LemmyPrivateFeedRule $privateFeedRule,
        private SearchAnswers $searchAnswers,
    ) {
    }

    public function fetchNewest(SocialAccount $account, string $query): TimelinePageModel
    {
        $answers = $this->searchAnswers->fetchAnswers(
            $account,
            $this->buildRequests($account, $query),
            static fn (FetchOutcomeModel $outcome): ApiResponseModel => LemmyApiClient::accepted(
                LemmyApiClient::answerOf($outcome),
            ),
        );

        return $this->buildPage($account, $answers, $query);
    }

    /**
     * @return non-empty-list<ApiRequestModel>
     *
     * @throws SearchQueryTooBroadException
     */
    public function buildRequests(SocialAccount $account, string $query): array
    {
        return array_map(
            fn (string $term): ApiRequestModel => $this->lemmy->searchGet($account, $term),
            $this->privateFeedRule->buildSearchQueries($query),
        );
    }

    /** @param list<ApiResponseModel> $accepted one per request, in order */
    public function buildPage(SocialAccount $account, array $accepted, string $query): TimelinePageModel
    {
        return $this->entries->searchPage($account, $accepted, $query);
    }
}
