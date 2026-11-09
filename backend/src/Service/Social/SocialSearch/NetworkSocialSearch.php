<?php

declare(strict_types=1);

namespace App\Service\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\BlueskySessions;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Support\BlueskySearchPage;
use App\Service\Social\Support\MastodonSearchPage;
use App\Service\Social\Support\MastodonSearchQueries;
use App\Service\Social\Support\SocialSearchPaths;

final readonly class NetworkSocialSearch implements SocialSearchInterface
{
    /** @param AnswerSettlerInterface<FetchOutcomeModel> $settler */
    public function __construct(
        private BlueskySessions $blueskySessions,
        private MastodonAccountApi $mastodon,
        private ConcurrentRequestEngine $engine,
        private AnswerSettlerInterface $settler,
        private ActingAccountGuard $guard,
        private TimelineSlotsModel $slots,
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
        };
    }

    /** @return list<ApiResponseModel> one accepted answer per alternative, in the alternatives' order */
    private function mastodonAnswers(SocialAccount $account, string $query): array
    {
        $tickets = array_map(
            fn (string $alternative): RequestTicketModel => $this->slots->ticketFor(
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
