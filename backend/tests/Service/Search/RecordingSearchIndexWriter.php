<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\IndexedEntry;
use App\Service\Search\Index\SearchIndexWriter;

/**
 * Records every call instead of talking to an engine, so EntryIndexerTest can
 * assert both WHAT was sent and, via $calls, the ORDER methods ran in
 * (index() must configure() before it upsert()s). An optional $failure lets a
 * test drive the "engine unavailable" path without a running Meilisearch.
 */
final class RecordingSearchIndexWriter implements SearchIndexWriter
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<list<IndexedEntry>> */
    public array $upserts = [];

    /** @var list<list<int>> */
    public array $forgets = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function configure(): void
    {
        $this->calls[] = 'configure';
        $this->throwIfConfiguredToFail();
    }

    public function upsert(array $entries): void
    {
        $this->calls[] = 'upsert';
        $this->upserts[] = $entries;
        $this->throwIfConfiguredToFail();
    }

    public function forget(array $entryIds): void
    {
        $this->calls[] = 'forget';
        $this->forgets[] = $entryIds;
        $this->throwIfConfiguredToFail();
    }

    public function clear(): void
    {
        $this->calls[] = 'clear';
        $this->throwIfConfiguredToFail();
    }

    private function throwIfConfiguredToFail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
