<?php

declare(strict_types=1);

namespace App\Service\Transcript\Model;

final readonly class TranscriptCueModel
{
    public function __construct(public ?int $startSeconds, public ?string $speaker, public string $text)
    {
    }
}
