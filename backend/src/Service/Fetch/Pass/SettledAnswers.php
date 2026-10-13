<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;

/**
 * One engine run's settler and queue: a first send goes out only when the settler admits it, and a raw outcome
 * becomes the key's finished value or a follow-up the queue sends as a fresh attempt.
 *
 * @template T
 */
final readonly class SettledAnswers
{
    /** @param AnswerSettlerInterface<T> $settler */
    public function __construct(private AnswerSettlerInterface $settler, private FetchQueue $queue)
    {
    }

    public function admits(FetchAttemptModel $attempt): bool
    {
        return !$attempt->isFirstSend() || $this->settler->admits($attempt->key);
    }

    /** @return \Generator<int|string, T> nothing when the outcome earned a follow-up */
    public function settle(int|string $key, FetchOutcomeModel $outcome): \Generator
    {
        $settlement = $this->settler->settle($key, $outcome);
        if ($settlement->isFollowUp()) {
            $this->queue->followUp($key, $settlement->followUpTicket());

            return;
        }

        yield $key => $settlement->settledOutcome();
    }
}
