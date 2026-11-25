<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

final readonly class OpmlPlacementChoiceModel
{
    /** @param array<int|string, string> $folderNames new folder names by tree position ("0", "0.1", ...) */
    public function __construct(
        public ?string $parentTagName,
        public array $folderNames = [],
    ) {
    }

    /** @param array<int|string, string> $folderNames */
    public static function fromTyped(?string $parentTagName, array $folderNames): self
    {
        return new self($parentTagName, array_map('trim', $folderNames));
    }
}
