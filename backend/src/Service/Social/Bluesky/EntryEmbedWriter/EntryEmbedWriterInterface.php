<?php

declare(strict_types=1);

namespace App\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;

interface EntryEmbedWriterInterface
{
    /** A post without an embed leaves the entry as it was. */
    public function fill(Entry $entry, JsonNodeModel $post): FillOutcome;
}
