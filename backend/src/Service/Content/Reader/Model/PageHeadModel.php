<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

/** The start of a page up to its head's end, or, for a file the response declares, only that file's type. */
final readonly class PageHeadModel
{
    private function __construct(
        public string $finalUrl,
        public string $head,
        public ?string $fileType,
    ) {
    }

    public static function ofPage(string $finalUrl, string $head): self
    {
        return new self($finalUrl, $head, null);
    }

    public static function ofFile(string $finalUrl, string $fileType): self
    {
        return new self($finalUrl, '', $fileType);
    }
}
