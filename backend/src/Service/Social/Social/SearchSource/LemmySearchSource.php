<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SearchSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\LemmyPostEntries;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\PrivateFeedRule\LemmyPrivateFeedRule;
use App\Service\Social\Social\SearchAnswers;
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
            array_map(
                fn (string $term): ApiRequestModel => $this->lemmy->searchGet($account, $term),
                $this->privateFeedRule->buildSearchQueries($query),
            ),
            static fn (FetchOutcomeModel $outcome): ApiResponseModel => LemmyApiClient::accepted(
                LemmyApiClient::answerOf($outcome),
            ),
        );

        return $this->entries->searchPage($account, $answers, $query);
    }
}
