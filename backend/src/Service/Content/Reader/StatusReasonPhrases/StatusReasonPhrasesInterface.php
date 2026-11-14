<?php

declare(strict_types=1);

namespace App\Service\Reader\StatusReasonPhrases;

interface StatusReasonPhrasesInterface
{
    /** The standard reason phrase for an HTTP status code, or '' for a code without one. */
    public function of(int $status): string;
}
