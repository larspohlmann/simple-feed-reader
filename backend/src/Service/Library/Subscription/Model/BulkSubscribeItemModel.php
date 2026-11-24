<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Model;

use App\Entity\Tag;
use App\Enum\SourceFormat;

/**
 * One feed to subscribe to in a batch. $feedTitle seeds a NEW shared Feed row so
 * the sidebar reads properly before the first fetch; it is ignored when the Feed
 * already exists, because another user's row is not ours to retitle.
 */
final readonly class BulkSubscribeItemModel
{
    /**
     * @param list<string> $tagPath group names, outermost first; the feed is tagged with the innermost
     * @param list<Tag> $chosenTags the account's own tags the feed joins as they are; read by the in-place path only
     */
    public function __construct(
        public string $feedUrl,
        public ?string $feedTitle = null,
        public array $tagPath = [],
        public ?TagStyleModel $tagStyle = null,
        public string $sourceFormat = SourceFormat::XML,
        public array $chosenTags = [],
    ) {
    }
}
