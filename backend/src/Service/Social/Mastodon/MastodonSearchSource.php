<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Mastodon\MastodonPrivateFeedRule;
use App\Service\Social\Mastodon\Support\MastodonSearchPage;
use App\Service\Social\Mastodon\Support\MastodonSearchPath;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\SearchAnswers;
use App\Service\Social\Social\SearchSource\SearchSourceInterface;
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
            $this->buildRequests($account, $query, null),
            static fn (FetchOutcomeModel $outcome): ApiResponseModel => MastodonApiClient::accepted(
                MastodonApiClient::answerOf($outcome),
            ),
        );

        return $this->buildPage($answers, $query);
    }

    /**
     * @return non-empty-list<ApiRequestModel>
     *
     * @throws SearchQueryTooBroadException
     */
    public function buildRequests(SocialAccount $account, string $query, ?string $newestSeenId): array
    {
        return array_map(
            fn (string $alternative): ApiRequestModel => $this->mastodon->authorizedGet(
                $account,
                MastodonSearchPath::buildPath($alternative, $newestSeenId),
            ),
            $this->privateFeedRule->buildSearchQueries($query),
        );
    }

    /** @param list<ApiResponseModel> $accepted one per request, in order */
    public function buildPage(array $accepted, string $query): TimelinePageModel
    {
        return MastodonSearchPage::of($accepted, $query);
    }
}
