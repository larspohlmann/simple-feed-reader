<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Outbound\Fetch\ConcurrentRequestEngine;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\RequestTicketModel;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\BlueskySessions;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\LemmyPostEntries;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\PrivateFeedRules;
use App\Service\Social\Social\Support\BlueskySearchPage;
use App\Service\Social\Social\Support\MastodonSearchPage;
use App\Service\Social\Social\Support\SocialSearchPaths;
use App\Service\Social\Social\TimelineRounds;

final readonly class NetworkSocialSearch implements SocialSearchInterface
{
    /** @param AnswerSettlerInterface<FetchOutcomeModel> $settler */
    public function __construct(
        private BlueskySessions $blueskySessions,
        private MastodonAccountApi $mastodon,
        private ConcurrentRequestEngine $engine,
        private AnswerSettlerInterface $settler,
        private ActingAccountGuard $guard,
        private TimelineRounds $timelineRounds,
        private LemmyAccountApi $lemmy,
        private LemmyPostEntries $lemmyEntries,
        private PrivateFeedRules $privateFeedRules,
    ) {
    }

    public function newest(SocialAccount $account, string $query): TimelinePageModel
    {
        return $this->guard->actAs($account, fn (): TimelinePageModel => $this->searchAs($account, $query));
    }

    private function searchAs(SocialAccount $account, string $query): TimelinePageModel
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => BlueskySearchPage::of(
                BlueskySessions::accepted($this->blueskySessions->askAs($account, SocialSearchPaths::bluesky($query))),
                $account->getIdentity(),
                $query,
            ),
            SocialNetwork::Mastodon => MastodonSearchPage::of($this->mastodonAnswers($account, $query), $query),
            SocialNetwork::Lemmy => $this->lemmyEntries->searchPage(
                $account,
                $this->lemmyAnswers($account, $query),
                $query,
            ),
        };
    }

    /** @return list<ApiResponseModel> */
    private function mastodonAnswers(SocialAccount $account, string $query): array
    {
        return $this->answers(
            $account,
            array_map(
                fn (string $alternative): ApiRequestModel => $this->mastodon->authorizedGet(
                    $account,
                    SocialSearchPaths::mastodon($alternative, null),
                ),
                $this->searchQueriesOf($account, $query),
            ),
            static fn (FetchOutcomeModel $outcome): ApiResponseModel => MastodonApiClient::accepted(
                MastodonApiClient::answerOf($outcome),
            ),
        );
    }

    /** @return list<ApiResponseModel> */
    private function lemmyAnswers(SocialAccount $account, string $query): array
    {
        return $this->answers(
            $account,
            array_map(
                fn (string $term): ApiRequestModel => $this->lemmy->searchGet($account, $term),
                $this->searchQueriesOf($account, $query),
            ),
            static fn (FetchOutcomeModel $outcome): ApiResponseModel => LemmyApiClient::accepted(
                LemmyApiClient::answerOf($outcome),
            ),
        );
    }

    /** @return non-empty-list<string> */
    private function searchQueriesOf(SocialAccount $account, string $query): array
    {
        return $this->privateFeedRules->selectRule($account->getNetwork())->buildSearchQueries($query);
    }

    /**
     * @param list<ApiRequestModel> $requests
     * @param \Closure(FetchOutcomeModel): ApiResponseModel $accept
     * @return list<ApiResponseModel> one accepted answer per request, in the requests' order
     */
    private function answers(SocialAccount $account, array $requests, \Closure $accept): array
    {
        $slots = $this->timelineRounds->slots();
        $tickets = array_map(
            static fn (ApiRequestModel $request): RequestTicketModel => $slots->ticketFor($account, $request),
            $requests,
        );

        $answers = [];
        foreach ($this->engine->sendAll($tickets, $this->settler) as $position => $outcome) {
            $answers[$position] = $accept($outcome);
        }

        ksort($answers);

        return array_values($answers);
    }
}
