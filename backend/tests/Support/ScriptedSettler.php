<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;

/**
 * An engine test's settler: it admits `$openings` sends and never a refused key, answers a key's scripted follow-ups
 * in turn, else passes the raw outcome through, and records every key it was asked about and settled.
 *
 * @implements AnswerSettlerInterface<FetchOutcomeModel>
 */
final class ScriptedSettler implements AnswerSettlerInterface
{
    /** @var list<int|string> */
    public array $asked = [];

    /** @var list<int|string> */
    public array $settled = [];

    /** @var array<int|string, list<RequestTicketModel>> */
    private array $followUps = [];

    /** @param list<int|string> $refused */
    public function __construct(public int $openings = \PHP_INT_MAX, private readonly array $refused = [])
    {
    }

    public function followUpWith(int|string $key, RequestTicketModel $ticket): void
    {
        $this->followUps[$key][] = $ticket;
    }

    public function admits(int|string $key): bool
    {
        $this->asked[] = $key;
        if ($this->openings < 1 || \in_array($key, $this->refused, true)) {
            return false;
        }
        --$this->openings;

        return true;
    }

    /** @return SettlementModel<FetchOutcomeModel> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        $this->settled[] = $key;
        $pending = $this->followUps[$key] ?? [];
        if ([] === $pending) {
            return SettlementModel::outcome($outcome);
        }
        $this->followUps[$key] = \array_slice($pending, 1);

        return SettlementModel::request($pending[0]);
    }
}
