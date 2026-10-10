<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\Model\JsonNodeModel;

/** Throws for the one post given, and fills every other one through the real writer. */
final readonly class FailingEmbedWriter implements EntryEmbedWriterInterface
{
    public function __construct(private string $failingGuid, private EntryEmbedWriterInterface $writer)
    {
    }

    public function fill(Entry $entry, JsonNodeModel $post): bool
    {
        if ($entry->getGuid() === $this->failingGuid) {
            throw new \RuntimeException('The embed could not be written.');
        }

        return $this->writer->fill($entry, $post);
    }
}
