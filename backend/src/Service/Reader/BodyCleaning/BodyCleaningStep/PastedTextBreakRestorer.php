<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Html\Support\PastedTextBreaks;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;

final readonly class PastedTextBreakRestorer implements BodyCleaningStepInterface
{
    public function cleanIn(BodyCleaningPass $pass): void
    {
        PastedTextBreaks::restoreIn($pass->document);
    }
}
