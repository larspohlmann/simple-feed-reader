<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Model;

/** The pages one share still needs read: its uncarded links, and the posted form of each link to check. */
final readonly class ShareFetchPlanModel
{
    /**
     * @param list<string>          $urls        tracking-free, in post order
     * @param array<string, string> $postedForms the href to compare against, by URL
     */
    public function __construct(
        public array $urls,
        public array $postedForms = [],
    ) {
    }

    public function fetches(): int
    {
        return \count($this->urls) + \count($this->postedForms);
    }

    #[\NoDiscard]
    public function withoutChecks(): self
    {
        return clone($this, ['postedForms' => []]);
    }
}
