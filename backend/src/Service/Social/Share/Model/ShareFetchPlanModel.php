<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Model;

use App\Entity\SharedLink;

/**
 * The pages one share still needs read: its uncarded links, and the posted form of each link to check. A link whose
 * shared link is known is attached as it is stored, with no read and no check.
 */
final readonly class ShareFetchPlanModel
{
    /**
     * @param list<string>              $urls        tracking-free, in post order
     * @param array<string, string>     $postedForms the href to compare against, by URL
     * @param array<string, SharedLink> $known       the stored link of a URL that needs no read, by URL
     */
    public function __construct(
        public array $urls,
        public array $postedForms = [],
        public array $known = [],
    ) {
    }

    public function fetches(): int
    {
        return \count($this->urls) - \count($this->known) + \count($this->postedForms);
    }

    #[\NoDiscard]
    public function withoutChecks(): self
    {
        return clone($this, ['postedForms' => []]);
    }

    /** @param array<string, SharedLink> $linksByUrl */
    #[\NoDiscard]
    public function withKnown(array $linksByUrl): self
    {
        $known = array_intersect_key($linksByUrl, array_flip($this->urls));

        return clone($this, ['known' => $known, 'postedForms' => array_diff_key($this->postedForms, $known)]);
    }
}
