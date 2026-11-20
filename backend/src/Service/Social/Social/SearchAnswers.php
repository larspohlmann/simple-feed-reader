<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Outbound\Fetch\ConcurrentRequestEngine;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\RequestTicketModel;

final readonly class SearchAnswers
{
    /** @param AnswerSettlerInterface<FetchOutcomeModel> $settler */
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private AnswerSettlerInterface $settler,
        private TimelineRounds $timelineRounds,
    ) {
    }

    /**
     * @param list<ApiRequestModel> $requests
     * @param \Closure(FetchOutcomeModel): ApiResponseModel $accept
     * @return list<ApiResponseModel> one accepted answer per request, in the requests' order
     */
    public function fetchAnswers(SocialAccount $account, array $requests, \Closure $accept): array
    {
        $slots = $this->timelineRounds->slots();
        $tickets = array_map(
            static fn (ApiRequestModel $request): RequestTicketModel => $slots->buildTicket($account, $request),
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
