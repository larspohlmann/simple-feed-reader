<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Entity\InterestPhrase;
use App\Entity\YourStreamTuning;

/** What the settings card shows of Your stream: its tuning and the phrases the AI last stored. */
final readonly class YourStreamSettingsStateModel
{
    /** @param list<InterestPhrase> $phrases */
    public function __construct(public YourStreamTuning $tuning, public array $phrases)
    {
    }
}
