<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Search\EntryIndexer;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The second half of one restore pass: the file's entries and entry states,
 * which together are everything that does not fit in memory at once.
 *
 * Constructed per restore, never shared — every field here is per-run working
 * state. It starts only once the account's shape is written and the entity
 * manager has been cleared, so it holds no entity from the earlier phases:
 * the feed ids, the "may I create entries here?" verdicts and the guid hash ⇒
 * entry id maps all arrive as the scalar RestoreFeedTarget set that survives
 * that clear, and the User arrives as a reference this class re-acquires after
 * every clear of its own.
 */
final class RestoreEntryLoader
{
    private const int BATCH = 500;

    /** @var array<string, RestoreFeedTarget> keyed by feed url */
    private array $targets = [];

    private ?User $user = null;

    private string $bufferedFeedUrl = '';

    /** @var list<EntryLine> */
    private array $bufferedLines = [];

    /** @var array<int, true> ids this restore created, as a set */
    private array $createdEntryIds = [];

    private int $entriesCreated = 0;

    private int $entryStatesCreated = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryBatchInserter $inserter,
        private readonly EntryIndexer $indexer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, RestoreFeedTarget> $targets
     */
    public function begin(array $targets, User $user): void
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

        $this->em->persist($this->stateFor($line, $entryId));
        ++$this->entryStatesCreated;
        if (0 === $this->entryStatesCreated % self::BATCH) {
            $this->flushStates();
        }
    }

    public function finish(): void
    {
        $this->closeBufferedFeed();
        $this->flushStates();
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

    /**
     * A backstop: BackupInspector refuses rows for an unsubscribed feed in
     * pass 1, while the account is still whole. Reaching this means the wipe
     * has already run, so the user must be told the account is empty.
     */
    private function target(string $feedUrl): RestoreFeedTarget
    {
        return $this->targets[$feedUrl] ?? throw BackupLoadFailedException::danglingReference(sprintf(
            'The backup carries rows for feed "%s", which none of its subscriptions names.',
            $feedUrl,
        ));
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
            throw BackupLoadFailedException::from($e);
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
        /** @var list<string> $hashes array_map over a non-empty-list stays a list */
        $hashes = array_map(static fn (EntryLine $line): string => $line->guidHash, $inserted);
        $idsByHash = $this->entries->entryIdsByGuidHash($target->feedId, $hashes);
        if (\count($idsByHash) !== \count($inserted)) {
            throw new \LogicException('An entry this restore just wrote cannot be read back.');
        }

        $target->learn($idsByHash);
        foreach ($idsByHash as $entryId) {
            $this->createdEntryIds[$entryId] = true;
        }
    }

    private function flushStates(): void
    {
        try {
            $this->em->flush();
        } catch (DbalException $e) {
            throw BackupLoadFailedException::from($e);
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
