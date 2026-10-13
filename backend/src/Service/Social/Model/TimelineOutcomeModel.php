<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What one private feed's read came to, for the refresh to store. */
final readonly class TimelineOutcomeModel
{
    private function __construct(
        public TimelineOutcome $outcome,
        private ?TimelinePageModel $page = null,
        private ?\RuntimeException $failure = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function fetched(TimelinePageModel $page): self
    {
        return new self(TimelineOutcome::Fetched, page: $page);
    }

    public static function throttled(?int $retryAfterSeconds): self
    {
        return new self(TimelineOutcome::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function failed(\RuntimeException $failure): self
    {
        return new self(TimelineOutcome::Failed, failure: $failure);
    }

    public static function accountThrottled(): self
    {
        return new self(TimelineOutcome::AccountThrottled);
    }

    public static function accountPaused(): self
    {
        return new self(TimelineOutcome::AccountPaused);
    }

    public function page(): TimelinePageModel
    {
        return $this->page ?? throw new \LogicException('Only a fetched outcome carries a page.');
    }

    public function failure(): \RuntimeException
    {
        return $this->failure ?? throw new \LogicException('Only a failed outcome carries its failure.');
    }
}
