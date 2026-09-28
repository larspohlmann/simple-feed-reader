<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use Dom\HTMLDocument;

final class BodyCleaningPasses
{
    public static function over(HTMLDocument $document): BodyCleaningPass
    {
        return new BodyCleaningPass($document, BodyCleaningInputs::nothingKnown());
    }
}
