<?php

declare(strict_types=1);

namespace App\Service\Fetch\AnswerSettler;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;

/**
 * The pass's side of an engine run: whether a request may go on the wire, and what each raw outcome comes to.
 *
 * @template-covariant T
 */
interface AnswerSettlerInterface
{
    /**
     * Asked once per first send, a follow-up included, as it is about to go on the wire rather than when it is pulled:
     * a parked request may wait a while. A retry or a redirect of a request already on the wire is never asked about.
     */
    public function admits(int|string $key): bool;

    /**
     * A request answering a withheld outcome must be admitted when next asked, or the engine asks forever.
     *
     * @return SettlementModel<T>
     */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel;
}
