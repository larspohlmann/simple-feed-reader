<?php

declare(strict_types=1);

namespace App\Service\Content\Transcript\TranscriptSource;

use App\Service\Content\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Content\Transcript\Exception\TranscriptUnusableException;

interface TranscriptSourceInterface
{
    /**
     * @throws TranscriptUnusableException
     * @throws TranscriptUnreachableException
     */
    public function bodyAt(string $url, float $budgetSeconds): string;
}
