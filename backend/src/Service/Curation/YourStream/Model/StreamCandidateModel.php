<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

final readonly class StreamCandidateModel
{
    /** @param list<int> $userIds the accounts whose Your stream subscriptions carry the entry's feed */
    public function __construct(public StreamEntryModel $entry, public array $userIds)
    {
    }
}
