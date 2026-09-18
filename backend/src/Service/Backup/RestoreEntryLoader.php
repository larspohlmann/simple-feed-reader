<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Search\EntryIndexer;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Loads one entry part's entries and entry states. Constructed per request,
 * never shared: every field is per-run working state, and the User is a
 * reference re-acquired after every clear().
 */
final class RestoreEntryLoader
{
    private const int BATCH = 500;

    private ?RestoreFeedTargets $targets = null;

    private ?User $user = null;

    private string $bufferedFeedUrl = '';

    /** @var list<EntryLine> */
    private array $bufferedLines = [];

    /** @var list<array{EntryStateLine, int}> states resolved to an entry id, not yet persisted */
    private array $heldStates = [];

    /** @var array<int, true> ids this restore created, as a set */
    private array $createdEntryIds = [];

    private int $entriesCreated = 0;

    private int $entryStatesCreated = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryStateRepository $entryStates,
        private readonly EntryBatchInserter $inserter,
        private readonly EntryIndexer $indexer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function begin(RestoreFeedTargets $targets, User $user): void
    {
        $this->targets = $targets;
        $this->user = $user;
    }

    public function bufferEntry(EntryLine $line): void
    {
        if ($line->feedUrl !== $this->bufferedFeedUrl) {
            $this->closeBufferedFeed();
            $this->bufferedFeedUrl = $line->feedUrl;
        }

        $this->bufferedLines[] = $line;
        if (\count($this->bufferedLines) >= self::BATCH) {
            $this->insertBufferedEntries();
        }
    }

    public function loadState(EntryStateLine $line): void
    {
        // Every entry the file carries is written before the first state is
        // read, so the hash maps below are final by now.
        $this->closeBufferedFeed();

        $entryId = $this->target($line->feedUrl)->entryId($line->guidHash);
        if (null === $entryId) {
            // The entry was withheld — a shared feed the restore may not add
            // to. Its state has nothing to attach to and is dropped with it.
            return;
        }

        $this->heldStates[] = [$line, $entryId];
        if (\count($this->heldStates) >= self::BATCH) {
            $this->writeHeldStates();
        }
    }

    public function finish(): void
    {
        $this->closeBufferedFeed();
        $this->writeHeldStates();
        $this->indexCreatedEntries();
    }

    public function entriesCreated(): int
    {
        return $this->entriesCreated;
    }

    public function entryStatesCreated(): int
    {
        return $this->entryStatesCreated;
    }

    private function stateFor(EntryStateLine $line, int $entryId): EntryState
    {
        $entry = $this->em->getReference(Entry::class, $entryId)
            ?? throw new \LogicException('An entry this restore just wrote has no reference.');
        $state = new EntryState($this->userReference(), $entry);
        $state->setIsHidden($line->isHidden);
        $state->setIsFavorite($line->isFavorite);
        $state->setIsKept($line->isKept);
        $state->setHiddenAt($line->hiddenAt);
        if ($line->isViewed) {
            // markViewed() is the only way in (#307, one-way by design) and it
            // needs an instant. A file that says "viewed" without a timestamp
            // keeps the flag — the fact that matters to the recommendation
            // history — and is stamped with the restore's own time.
            $state->markViewed($line->viewedAt ?? $this->clock->now());
        }

        return $state;
    }

    private function target(string $feedUrl): RestoreFeedTarget
    {
        return $this->targetsOrThrow()->for($feedUrl);
    }

    private function targetsOrThrow(): RestoreFeedTargets
    {
        return $this->targets ?? throw new \LogicException('begin() must run before entries are loaded.');
    }

    private function closeBufferedFeed(): void
    {
        if ('' === $this->bufferedFeedUrl) {
            return;
        }

        $this->insertBufferedEntries();
        $this->bufferedFeedUrl = '';
    }

    private function insertBufferedEntries(): void
    {
        $lines = $this->bufferedLines;
        $this->bufferedLines = [];
        if ([] === $lines) {
            return;
        }

        $target = $this->target($this->bufferedFeedUrl);
        if (!$target->acceptsNewEntries) {
            return;
        }

        $fresh = $this->unknownOf($lines, $target);
        if ([] === $fresh) {
            return;
        }

        try {
            $this->inserter->insert($target->feedId, $fresh);
        } catch (DbalException $e) {
            throw BackupLoadFailedException::duringEntries($e);
        }

        $this->entriesCreated += \count($fresh);
        $this->recordCreatedIds($target, $fresh);
    }

    /**
     * EntryBatchInserter's contract puts de-duplication on its caller, so this
     * drops both the hashes the feed already holds and any the file repeats.
     *
     * @param list<EntryLine> $lines
     *
     * @return list<EntryLine>
     */
    private function unknownOf(array $lines, RestoreFeedTarget $target): array
    {
        $freshByHash = [];
        foreach ($lines as $line) {
            if (!$target->knowsEntry($line->guidHash)) {
                $freshByHash[$line->guidHash] = $line;
            }
        }

        return array_values($freshByHash);
    }

    /**
     * The multi-row INSERT yields no per-row lastInsertId, so the ids are read
     * back by the hashes just written — at most one batch of them (#456).
     *
     * @param non-empty-list<EntryLine> $inserted
     */
    private function recordCreatedIds(RestoreFeedTarget $target, array $inserted): void
    {
        $idsByHash = $this->entries->entryIdsByGuidHash($target->feedId, array_column($inserted, 'guidHash'));
        if (\count($idsByHash) !== \count($inserted)) {
            throw new \LogicException('An entry this restore just wrote cannot be read back.');
        }

        $target->learn($idsByHash);
        foreach ($idsByHash as $entryId) {
            $this->createdEntryIds[$entryId] = true;
        }
    }

    /**
     * An existing state row wins over the file: that keeps a retried part
     * idempotent and a click made mid-restore intact.
     */
    private function writeHeldStates(): void
    {
        $held = $this->heldStates;
        $this->heldStates = [];
        if ([] === $held) {
            return;
        }

        $entryIds = array_map(static fn (array $pair): int => $pair[1], $held);
        $userId = (int) $this->userReference()->getId();
        $alreadyStated = array_flip($this->entryStates->entryIdsWithStateForUser($userId, $entryIds));
        foreach ($held as [$line, $entryId]) {
            if (isset($alreadyStated[$entryId])) {
                continue;
            }

            $this->em->persist($this->stateFor($line, $entryId));
            ++$this->entryStatesCreated;
        }

        $this->flushStates();
    }

    private function flushStates(): void
    {
        try {
            $this->em->flush();
        } catch (DbalException $e) {
            throw BackupLoadFailedException::duringEntries($e);
        }

        $userId = (int) $this->userReference()->getId();
        $this->em->clear();
        $this->user = $this->em->getReference(User::class, $userId);
    }

    /**
     * Hands the created entries to the search index in the same keyset batches
     * app:search:reindex walks in, so a restore of any size costs one batch of
     * hydrated entities at a time. Nothing created means nothing to walk.
     */
    private function indexCreatedEntries(): void
    {
        if ([] === $this->createdEntryIds) {
            return;
        }

        $lastId = min(array_keys($this->createdEntryIds)) - 1;
        do {
            $batch = $this->entries->entriesAfterId($lastId, self::BATCH);
            $created = [];
            foreach ($batch as $entry) {
                $lastId = (int) $entry->getId();
                if (isset($this->createdEntryIds[$lastId])) {
                    $created[] = $entry;
                }
            }
            $this->indexer->index($created);
            $batchSize = \count($batch);
            $this->em->clear();
        } while (self::BATCH === $batchSize);
    }

    private function userReference(): User
    {
        return $this->user ?? throw new \LogicException('begin() must run before entries are loaded.');
    }
}
