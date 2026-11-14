<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\QuotedPostSource;

use App\Entity\QuotedPostContent;
use App\Service\Content\LinkCard\Exception\QuotedPostUnavailableException;

interface QuotedPostSourceInterface
{
    /** @throws QuotedPostUnavailableException when the address names no post this source reads, or reading it failed */
    public function quotedPostAt(string $address): QuotedPostContent;
}
