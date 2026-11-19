<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SearchSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\PrivateFeedRule\MastodonPrivateFeedRule;
use App\Service\Social\Social\SearchAnswers;
use App\Service\Social\Social\Support\MastodonSearchPage;
use App\Service\Social\Social\Support\MastodonSearchPath;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonSearchSource implements SearchSourceInterface
{
    public function __construct(
        private MastodonAccountApi $mastodon,
        private MastodonPrivateFeedRule $privateFeedRule,
        private SearchAnswers $searchAnswers,
    ) {
    }

    public function fetchNewest(SocialAccount $account, string $query): TimelinePageModel
    {
        $answers = $this->searchAnswers->fetchAnswers(
            $account,
            array_map(
                fn (string $alternative): ApiRequestModel => $this->mastodon->authorizedGet(
                    $account,
                    MastodonSearchPath::buildPath($alternative, null),
                ),
                $this->privateFeedRule->buildSearchQueries($query),
            ),
            static fn (FetchOutcomeModel $outcome): ApiResponseModel => MastodonApiClient::accepted(
                MastodonApiClient::answerOf($outcome),
            ),
        );

        return MastodonSearchPage::of($answers, $query);
    }
}
