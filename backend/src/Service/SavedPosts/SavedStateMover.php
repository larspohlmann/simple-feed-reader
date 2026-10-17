<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\EntryState;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedStateMover
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SocialLikeRepository $socialLikes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<int, int> $targetByEntryId the same post's target entry id, by its source entry id */
    public function move(int $userId, array $targetByEntryId): void
    {
        if ([] === $targetByEntryId) {
            return;
        }
        $sources = $this->entryStates->forUserByEntryIds($userId, array_keys($targetByEntryId));
        foreach ($sources as $entryId => $source) {
            $this->entryStates->ensureRow(
                $userId,
                $targetByEntryId[$entryId],
                $source->isHidden() ? $source->getHiddenAt() : null,
            );
        }
        $targets = $this->entryStates->forUserByEntryIds(
            $userId,
            array_values(array_intersect_key($targetByEntryId, $sources)),
        );
        foreach ($sources as $entryId => $source) {
            $target = $targets[$targetByEntryId[$entryId]] ?? null;
            if (null !== $target) {
                self::carry($source, $target);
            }
        }
        foreach ($targetByEntryId as $sourceEntryId => $targetEntryId) {
            $this->socialLikes->copyLike($userId, $sourceEntryId, $targetEntryId);
        }
        $this->entityManager->flush();
    }

    /** A legacy read mark has no instant, which ensureRow() cannot write; restoreReadMark() can. */
    private static function carry(EntryState $source, EntryState $target): void
    {
        if ($source->isHidden() && !$target->isHidden()) {
            $target->restoreReadMark(new BackedUpReadMark(true, $source->getHiddenAt()));
        }
        if ($source->isFavorite()) {
            $target->markFavorite();
            $source->clearFavorite();
        }
        if ($source->isKept()) {
            $target->markKept();
            $source->clearKept();
        }
        $viewedAt = $source->getViewedAt();
        if (null !== $viewedAt) {
            $target->markViewed($viewedAt);
        }
    }
}
