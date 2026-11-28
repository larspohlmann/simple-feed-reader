<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Entity\YourStreamTuning;
use App\Entity\YourStreamWord;

final readonly class YourStreamSettingsStateModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     * @param list<YourStreamWord> $words
     */
    public function __construct(public YourStreamTuning $tuning, public array $words)
    {
    }
}
