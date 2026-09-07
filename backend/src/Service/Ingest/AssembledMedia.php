<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;

/** The two entity-ready media lists the assembler hands the ingestor. */
final readonly class AssembledMedia
{
    /**
     * @param list<EntryMedium>     $media
     * @param list<EntryAttachment> $attachments
     */
    public function __construct(
        public array $media,
        public array $attachments,
    ) {
    }
}
