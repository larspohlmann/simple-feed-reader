<?php

declare(strict_types=1);

namespace App\Service\Parser;

final readonly class ParsedFeed
{
    /**
     * @param list<ParsedEntry> $entries
     */
    public function __construct(
        public ?string $title,
        public ?string $siteUrl,
        public ?string $description,
        public ?string $imageUrl,
        public array $entries,
    ) {
    }

    /**
     * The same feed with a different entry list. Callers that narrow the
     * entries — FirstFetchRecorder caps them on subscribe — used to rebuild
     * the object field by field, which quietly dropped every field added
     * afterwards. Copying here means a new field is carried by construction.
     *
     * @param list<ParsedEntry> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self($this->title, $this->siteUrl, $this->description, $this->imageUrl, $entries);
    }
}
