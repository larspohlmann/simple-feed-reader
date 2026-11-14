<?php

declare(strict_types=1);

namespace App\Service\Category\Model;

final readonly class NormalizedCategoryModel
{
    public function __construct(
        public string $canonicalKey,
        public string $displayLabel,
        public string $scheme,
    ) {
    }

    /** The global identity key: same canonical key AND scheme is the same category. */
    public function identity(): string
    {
        return $this->canonicalKey . "\0" . $this->scheme;
    }
}
