<?php

declare(strict_types=1);

namespace App\Service\Reader;

interface StatusReasonPhrases
{
    /** The standard reason phrase for an HTTP status code, or '' for a code without one. */
    public function of(int $status): string;
}
