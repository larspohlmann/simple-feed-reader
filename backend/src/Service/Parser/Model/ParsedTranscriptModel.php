<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Enum\TranscriptFormat;

/** The one transcript an item declared that ingest keeps. */
final readonly class ParsedTranscriptModel
{
    public function __construct(
        public string $url,
        public TranscriptFormat $format,
        public ?string $language,
    ) {
    }
}
