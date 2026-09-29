<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\Model\IndexedEntryModel;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;

/**
 * Records what was sent and, in $calls, the order (index() must configure() before it upsert()s); an optional
 * $failure drives the engine-unavailable path.
 */
final class RecordingSearchIndexWriter implements SearchIndexWriterInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<list<IndexedEntryModel>> */
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
