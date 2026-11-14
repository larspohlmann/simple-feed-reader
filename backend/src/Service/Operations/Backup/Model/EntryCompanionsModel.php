<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

use App\Entity\EntryOrigin;
use App\Entity\LinkCardContent;

/** What an entry line carries besides the entry: its link cards and, for a saved copy, its origin. */
final readonly class EntryCompanionsModel
{
    /** @param list<LinkCardContent> $linkCards */
    public function __construct(
        public array $linkCards = [],
        public ?EntryOrigin $origin = null,
    ) {
    }
}
