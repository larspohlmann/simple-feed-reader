<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** What an exchange concluded from one chunk: wait for more, the request's outcome, or the attempt to send next. */
final readonly class ExchangeStepModel
{
    private function __construct(
        public ?FetchOutcomeModel $outcome,
        public ?FetchAttemptModel $nextAttempt,
    ) {
    }

    public static function awaitBody(): self
    {
        return new self(null, null);
    }

    public static function answered(FetchOutcomeModel $outcome): self
    {
        return new self($outcome, null);
    }

    public static function sendNext(FetchAttemptModel $attempt): self
    {
        return new self(null, $attempt);
    }

    public function isAwaitingBody(): bool
    {
        return null === $this->outcome && null === $this->nextAttempt;
    }
}
