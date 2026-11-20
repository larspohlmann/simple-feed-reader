<?php

declare(strict_types=1);

namespace App\Service\Library\Reading;

use App\Entity\Entry;
use App\Enum\CopyReadChange;
use App\Repository\EntryReadMarkRepository;
use App\Repository\ReadMarking;
use App\Service\Library\Reading\Model\EntryStateChangeModel;
use Psr\Clock\ClockInterface;

/**
 * Keeps the copies of one item reading alike at write time: a read, un-read, view or un-view spreads to the user's
 * other copies, and a copy that arrives later inherits the read. An article without an item shares its read state
 * by address only through a single toggle: an address leg of the All sweep took over a second (#2404).
 */
final readonly class CopyReadStatePropagator
{
    public function __construct(
        private EntryReadMarkRepository $readMarks,
        private ClockInterface $clock,
    ) {
    }

    public function spreadChange(int $userId, Entry $entry, EntryStateChangeModel $change): void
    {
        $marking = new ReadMarking($userId, $this->clock->now());
        foreach (self::collectCopyChanges($change) as $copyChange) {
            if (null !== $entry->getCanonicalItemId()) {
                $this->readMarks->spreadToItemCopies($copyChange, $marking, [$entry->requireId()]);
            } elseif (null !== $entry->getUrlHash()) {
                $this->readMarks->spreadToAddressCopies($copyChange, $marking, [$entry->requireId()]);
            }
        }
    }

    /** @param list<int> $entryIds one batch */
    public function spreadRead(int $userId, array $entryIds): void
    {
        $this->readMarks->spreadToItemCopies(
            CopyReadChange::Read,
            new ReadMarking($userId, $this->clock->now()),
            $entryIds,
        );
    }

    /** @param list<int> $feedIds */
    public function spreadSweep(int $userId, array $feedIds, \DateTimeImmutable $until): void
    {
        $this->readMarks->spreadReadFromFeedsUntil(new ReadMarking($userId, $this->clock->now()), $feedIds, $until);
    }

    /**
     * @param list<Entry> $created flushed, so each has its id
     *
     * @return int the read states inherited
     */
    public function inheritRead(array $created): int
    {
        $linkedIds = [];
        foreach ($created as $entry) {
            if (null !== $entry->getCanonicalItemId()) {
                $linkedIds[] = $entry->requireId();
            }
        }

        return $this->readMarks->inheritReadFromItemCopies($linkedIds, $this->clock->now());
    }

    /** @return list<CopyReadChange> */
    private static function collectCopyChanges(EntryStateChangeModel $change): array
    {
        $copyChanges = [];
        if (null !== $change->isHidden) {
            $copyChanges[] = CopyReadChange::fromHidden($change->isHidden);
        }
        if (null !== $change->isViewed) {
            $copyChanges[] = CopyReadChange::fromViewed($change->isViewed);
        }

        return $copyChanges;
    }
}
