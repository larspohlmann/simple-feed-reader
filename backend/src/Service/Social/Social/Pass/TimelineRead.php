<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Pass;

use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;

/** One feed's reads in a round: its requests, sent one after another, and the answers accepted so far. */
final class TimelineRead
{
    /** @var list<ApiResponseModel> */
    private array $accepted = [];

    /** @param non-empty-list<ApiRequestModel> $requests */
    public function __construct(private readonly array $requests)
    {
    }

    public function current(): ApiRequestModel
    {
        return $this->requests[\count($this->accepted)];
    }

    public function accept(ApiResponseModel $answer): void
    {
        $this->accepted[] = $answer;
    }

    public function isComplete(): bool
    {
        return \count($this->accepted) >= \count($this->requests);
    }

    /** @return list<ApiResponseModel> */
    public function accepted(): array
    {
        return $this->accepted;
    }
}
