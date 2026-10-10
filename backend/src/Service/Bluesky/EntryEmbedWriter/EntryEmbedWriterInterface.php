<?php

declare(strict_types=1);

namespace App\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Service\Bluesky\Model\JsonNodeModel;

interface EntryEmbedWriterInterface
{
    /** Whether the post brought an embed; one without leaves the entry as it was. */
    public function fill(Entry $entry, JsonNodeModel $post): bool;
}
