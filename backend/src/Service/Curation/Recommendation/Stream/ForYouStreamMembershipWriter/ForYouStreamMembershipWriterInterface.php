<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\ForYouStreamMembershipWriter;

use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamMatchModel;

/** The one write the stream sweep makes: store the pairs a chunk matched. */
interface ForYouStreamMembershipWriterInterface
{
    /**
     * @param list<ForYouStreamMatchModel> $matches
     *
     * @return int the pairs that were not stored yet
     */
    public function insertMissing(array $matches, \DateTimeImmutable $matchedAt): int;
}
