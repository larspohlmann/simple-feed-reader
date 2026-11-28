<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\YourStreamMembershipWriter;

use App\Service\Curation\YourStream\Model\YourStreamMatchModel;

interface YourStreamMembershipWriterInterface
{
    /**
     * @param list<YourStreamMatchModel> $matches
     *
     * @return int the pairs that were not stored yet
     */
    public function insertMissing(array $matches, \DateTimeImmutable $matchedAt): int;
}
