<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Foundation\Html\Support\PastedTextBreaks;
use App\Service\Content\Reader\BodyCleaning\Pass\BodyCleaningPass;

final readonly class PastedTextBreakRestorer implements BodyCleaningStepInterface
{
    public function cleanIn(BodyCleaningPass $pass): void
    {
        PastedTextBreaks::restoreIn($pass->document);
    }
}
