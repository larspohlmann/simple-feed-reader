<?php

declare(strict_types=1);

namespace App\Service\Opml\Model;

/** Where one imported feed goes: its tag, and that tag's parent when the outline sat under a nested group. */
final readonly class OpmlFeedPlacementModel
{
    public function __construct(
        public string $feedUrl,
        public ?string $tagName,
        public ?string $parentTagName,
    ) {
    }
}
