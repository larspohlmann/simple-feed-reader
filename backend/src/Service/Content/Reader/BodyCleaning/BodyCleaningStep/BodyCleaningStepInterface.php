<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Content\Reader\BodyCleaning\Pass\BodyCleaningPass;

/** One step of the reader body clean; it mutates the pass's shared document in place. */
interface BodyCleaningStepInterface
{
    public function cleanIn(BodyCleaningPass $pass): void;
}
