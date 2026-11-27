<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

final readonly class StreamCandidateModel
{
    /** @param list<int> $userIds the accounts whose For you subscriptions carry the entry's feed */
    public function __construct(public StreamEntryModel $entry, public array $userIds)
    {
    }
}
