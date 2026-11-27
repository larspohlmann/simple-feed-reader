<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

use App\Entity\ForYouStreamTuning;
use App\Entity\InterestPhrase;

/** What the settings card shows of the For you stream: its tuning and the phrases the AI last stored. */
final readonly class ForYouStreamStateModel
{
    /** @param list<InterestPhrase> $phrases */
    public function __construct(public ForYouStreamTuning $tuning, public array $phrases)
    {
    }
}
