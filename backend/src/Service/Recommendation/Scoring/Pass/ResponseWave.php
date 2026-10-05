<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Pass;

use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseWave
{
    /** @var \SplObjectStorage<ResponseInterface, int> */
    private \SplObjectStorage $positions;

    /** @var array<int, ScoringOutcomeModel> */
    private array $outcomes = [];

    /** @var array<int, float> */
    private array $lastHeardAt = [];

    public function __construct(
        private readonly ClockInterface $clock,
        public readonly ProviderCredentialsModel $credentials,
    ) {
        $this->positions = new \SplObjectStorage();
    }

    public function await(int $position, ResponseInterface $response): void
    {
        $this->positions[$response] = $position;
        $this->heardFrom($response);
    }

    public function heardFrom(ResponseInterface $response): void
    {
        $this->lastHeardAt[$this->positions[$response]] = $this->now();
    }

    public function isSettled(ResponseInterface $response): bool
    {
        return isset($this->outcomes[$this->positions[$response]]);
    }

    public function settle(ResponseInterface $response, ScoringOutcomeModel $outcome): void
    {
        $this->settleAt($this->positions[$response], $outcome);
    }

    public function settleAt(int $position, ScoringOutcomeModel $outcome): void
    {
        $this->outcomes[$position] = $outcome;
    }

    public function failSilentFor(float $idleSeconds): void
    {
        $now = $this->now();
        foreach ($this->positions as $response) {
            $position = $this->positions->getInfo();
            if (isset($this->outcomes[$position]) || $now - $this->lastHeardAt[$position] <= $idleSeconds) {
                continue;
            }
            $response->cancel();
            $this->settleAt($position, ScoringOutcomeModel::failed(new ProviderUnreachableException(
                sprintf('That provider sent nothing for more than %s seconds.', $idleSeconds),
            )));
        }
    }

    /** @return list<ResponseInterface> the responses still without an outcome */
    public function openResponses(): array
    {
        $open = [];
        foreach ($this->positions as $response) {
            if (!$this->isSettled($response)) {
                $open[] = $response;
            }
        }

        return $open;
    }

    /** @return list<ScoringOutcomeModel> in request order */
    public function outcomes(): array
    {
        $outcomes = $this->outcomes;
        ksort($outcomes);

        return array_values($outcomes);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
