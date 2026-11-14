<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\NetworkWithoutFeedsException;
use App\Service\Outbound\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Outbound\Fetch\ConcurrentRequestEngine;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\RequestTicketModel;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\BlueskySessions;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\Support\BlueskySearchPage;
use App\Service\Social\Social\Support\MastodonSearchPage;
use App\Service\Social\Social\Support\MastodonSearchQueries;
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
            SocialNetwork::Lemmy => throw new NetworkWithoutFeedsException(),
        };
    }

    /** @return list<ApiResponseModel> one accepted answer per alternative, in the alternatives' order */
    private function mastodonAnswers(SocialAccount $account, string $query): array
    {
        $slots = $this->timelineRounds->slots();
        $tickets = array_map(
            fn (string $alternative): RequestTicketModel => $slots->ticketFor(
                $account,
                $this->mastodon->authorizedGet($account, SocialSearchPaths::mastodon($alternative, null)),
            ),
            MastodonSearchQueries::of($query),
        );

        $answers = [];
        foreach ($this->engine->sendAll($tickets, $this->settler) as $position => $outcome) {
            $answers[$position] = MastodonApiClient::accepted(MastodonApiClient::answerOf($outcome));
        }

        return array_values($answers);
    }
}
