<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

final readonly class OpmlPreviewModel
{
    /** @param array<string, OpmlFeedState> $states by feed URL */
    public function __construct(
        public int $freeSlots,
        public OpmlPlacedFolderModel $root,
        public array $states,
    ) {
    }
}
