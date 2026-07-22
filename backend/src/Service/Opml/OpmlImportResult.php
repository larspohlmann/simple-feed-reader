<?php

declare(strict_types=1);

namespace App\Service\Opml;

final readonly class OpmlImportResult
{
    public function __construct(
        public int $imported = 0,
        public int $alreadySubscribed = 0,
        public int $invalid = 0,
        public int $skippedOverLimit = 0,
    ) {
    }

    public function with(
        int $imported = 0,
        int $alreadySubscribed = 0,
        int $invalid = 0,
        int $skippedOverLimit = 0,
    ): self {
        return new self(
            $this->imported + $imported,
            $this->alreadySubscribed + $alreadySubscribed,
            $this->invalid + $invalid,
            $this->skippedOverLimit + $skippedOverLimit,
        );
    }
}
