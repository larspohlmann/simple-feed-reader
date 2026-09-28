<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;

/** One step of the reader body clean; it mutates the pass's shared document in place. */
interface BodyCleaningStepInterface
{
    public function cleanIn(BodyCleaningPass $pass): void;
}
