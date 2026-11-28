<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Factory;

use App\Service\Curation\YourStream\Pass\EntryStemming;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\WordEquivalents;

final readonly class EntryStemmingFactory
{
    public function __construct(private WordEquivalents $equivalents, private StopWordStems $stopWordStems)
    {
    }

    public function create(): EntryStemming
    {
        return new EntryStemming($this->equivalents, $this->stopWordStems);
    }
}
