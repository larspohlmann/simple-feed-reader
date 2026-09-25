<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Service\Backup\Dto\BackupTotals;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One export's walk over its subscribed feeds' entries, batched by keyset id
 * and buffered into byte-budgeted parts. The buffer, the provenance every
 * part's header repeats, and the running totals live here as fields for the
 * whole walk rather than travelling through method parameters, because they
 * are this walk's own state, not values its callers need to know about.
 */
final class BackupPartWalk
{
    private const int ENTRY_BATCH = 500;

    private readonly BackupPartBuffer $buffer;
    private int $partsWritten = 0;
    private int $totalEntries = 0;
    private int $totalEntryStates = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryStateRepository $entryStates,
        private readonly BackupLines $lines,
        private readonly BackupProvenance $provenance,
        private readonly int $userId,
    ) {
        $this->buffer = new BackupPartBuffer();
    }

    /**
     * @param array<int, string> $feedUrlsByFeedId
     *
     * @return \Generator<int, BackupPart>
     */
    public function entryParts(array $feedUrlsByFeedId): \Generator
    {
        foreach ($feedUrlsByFeedId as $feedId => $feedUrl) {
            yield from $this->walkFeed($feedId, $feedUrl);
        }

        if (!$this->buffer->isEmpty()) {
            yield $this->closePart();
        }
    }

    public function partsWritten(): int
    {
        return $this->partsWritten;
    }

    public function totals(): BackupTotals
    {
        return new BackupTotals($this->totalEntries, $this->totalEntryStates);
    }

    /**
     * @return \Generator<int, BackupPart>
     */
    private function walkFeed(int $feedId, string $feedUrl): \Generator
    {
        $lastId = 0;
        do {
            $batch = $this->entries->forFeedAfterId($feedId, $lastId, self::ENTRY_BATCH);
            yield from $this->bufferBatch($batch, $feedUrl);
            $batchSize = \count($batch);
            $lastId = $this->lastIdOf($batch, $lastId);
            $this->em->clear();
        } while (self::ENTRY_BATCH === $batchSize);
    }

    /**
     * @param list<Entry> $batch
     *
     * @return \Generator<int, BackupPart>
     */
    private function bufferBatch(array $batch, string $feedUrl): \Generator
    {
        $statesByEntryId = $this->entryStates->forUserByEntryIds(
            $this->userId,
            array_map(static fn (Entry $entry): int => $entry->requireId(), $batch),
        );

        foreach ($batch as $entry) {
            yield from $this->bufferEntry($entry, $feedUrl, $statesByEntryId[$entry->requireId()] ?? null);
        }
    }

    /**
     * @return \Generator<int, BackupPart>
     */
    private function bufferEntry(Entry $entry, string $feedUrl, ?EntryState $state): \Generator
    {
        $entryLine = $this->lines->entryLine($entry, $feedUrl);
        $stateLine = null !== $state ? $this->lines->entryStateLine($state, $feedUrl) : null;

        $this->buffer->add($entryLine, $stateLine);
        ++$this->totalEntries;
        if (null !== $stateLine) {
            ++$this->totalEntryStates;
        }

        if ($this->buffer->isFull()) {
            yield $this->closePart();
        }
    }

    private function closePart(): BackupPart
    {
        ++$this->partsWritten;
        $header = $this->lines->entryPartHeader($this->provenance, $this->partsWritten);
        $footer = $this->lines->footerLine([
            BackupSchema::KIND_ENTRY => $this->buffer->entryCount(),
            BackupSchema::KIND_ENTRY_STATE => $this->buffer->entryStateCount(),
        ]);

        return BackupPart::entries($this->partsWritten, $this->buffer->drain($header, $footer));
    }

    /**
     * @param list<Entry> $batch
     */
    private function lastIdOf(array $batch, int $fallback): int
    {
        $lastKey = array_key_last($batch);

        return null === $lastKey ? $fallback : $batch[$lastKey]->requireId();
    }
}
