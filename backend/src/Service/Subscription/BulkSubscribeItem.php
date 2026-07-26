<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Enum\SourceFormat;

/**
 * One feed to subscribe to in a batch. $feedTitle seeds a NEW shared Feed row so
 * the sidebar reads properly before the first fetch; it is ignored when the Feed
 * already exists, because another user's row is not ours to retitle.
 */
final readonly class BulkSubscribeItem
{
    public function __construct(
        public string $feedUrl,
        public ?string $feedTitle = null,
        public ?string $tagName = null,
        public ?TagStyle $tagStyle = null,
        public string $sourceFormat = SourceFormat::XML,
    ) {
    }
}
