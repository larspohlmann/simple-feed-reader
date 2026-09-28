<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

/** One step of the reader body clean; it mutates the pass's shared document in place. */
interface BodyCleaningStep
{
    public function cleanIn(BodyCleaningPass $pass): void;
}
