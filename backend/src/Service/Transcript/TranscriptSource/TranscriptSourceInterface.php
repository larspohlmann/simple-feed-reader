<?php

declare(strict_types=1);

namespace App\Service\Transcript\TranscriptSource;

use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;

interface TranscriptSourceInterface
{
    /**
     * @throws TranscriptUnusableException
     * @throws TranscriptUnreachableException
     */
    public function bodyAt(string $url, float $budgetSeconds): string;
}
