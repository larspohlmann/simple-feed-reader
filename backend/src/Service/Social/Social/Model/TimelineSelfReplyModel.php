<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/** An author's reply to their own post, as a timeline answered it: the entry it becomes when its root is unknown. */
final readonly class TimelineSelfReplyModel
{
    public function __construct(
        public string $rootGuid,
        public string $parentUrl,
        public ParsedEntryModel $entry,
        public JsonNodeModel $post,
    ) {
    }
}
