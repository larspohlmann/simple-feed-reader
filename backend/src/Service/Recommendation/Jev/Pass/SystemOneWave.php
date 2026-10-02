<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Pass;

use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** One evaluateMany() call's responses: each one's request position, its outcome once settled, when it last spoke. */
final class SystemOneWave
{
    /** @var \SplObjectStorage<ResponseInterface, int> */
    private \SplObjectStorage $positions;

    /** @var array<int, SystemOneOutcomeModel> */
    private array $outcomes = [];

    /** @var array<int, float> */
    private array $lastHeardAt = [];

    public function __construct(private readonly ClockInterface $clock)
    {
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

    public function settle(ResponseInterface $response, SystemOneOutcomeModel $outcome): void
    {
        $this->settleAt($this->positions[$response], $outcome);
    }

    public function settleAt(int $position, SystemOneOutcomeModel $outcome): void
    {
        $this->outcomes[$position] = $outcome;
    }

    public function failSilentFor(float $idleSeconds): void
    {
        foreach ($this->positions as $response) {
            $position = $this->positions->getInfo();
            if (isset($this->outcomes[$position]) || $this->now() - $this->lastHeardAt[$position] <= $idleSeconds) {
                continue;
            }
            $response->cancel();
            $this->settleAt($position, SystemOneOutcomeModel::failed(new ProviderUnreachableException(
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

    /** @return list<SystemOneOutcomeModel> */
    public function outcomes(int $requestCount): array
    {
        $aligned = [];
        for ($position = 0; $position < $requestCount; $position++) {
            $aligned[] = $this->outcomes[$position] ?? SystemOneOutcomeModel::failed(
                new ProviderUnreachableException('That provider answered without a reply.'),
            );
        }

        return $aligned;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
