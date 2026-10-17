<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\EntryState;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Moves a user's marks from one entry to another of the same post: favourite and kept merge, the source's go. */
final readonly class SavedStateMover
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SocialLikeRepository $socialLikes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<int, int> $targetByEntryId */
    public function move(int $userId, array $targetByEntryId): void
    {
        if ([] === $targetByEntryId) {
            return;
        }
        $sources = $this->entryStates->forUserByEntryIds($userId, array_keys($targetByEntryId));
        foreach ($targetByEntryId as $entryId => $targetId) {
            $this->entryStates->ensureRow($userId, $targetId, self::hiddenSinceOf($sources[$entryId] ?? null));
        }
        $targets = $this->entryStates->forUserByEntryIds($userId, array_values($targetByEntryId));
        foreach ($targetByEntryId as $entryId => $targetId) {
            self::merge($sources[$entryId] ?? null, $targets[$targetId] ?? null);
            $this->socialLikes->copyLike($userId, $entryId, $targetId);
        }
        $this->entityManager->flush();
    }

    private static function hiddenSinceOf(?EntryState $source): ?\DateTimeImmutable
    {
        return true === $source?->isHidden() ? $source->getHiddenAt() : null;
    }

    private static function merge(?EntryState $source, ?EntryState $target): void
    {
        if (null === $source || null === $target) {
            return;
        }
        self::moveFlags($source, $target);
        self::copyReading($source, $target);
    }

    private static function moveFlags(EntryState $source, EntryState $target): void
    {
        if ($source->isFavorite()) {
            $target->markFavorite();
        }
        if ($source->isKept()) {
            $target->markKept();
        }
        $source->clearFavorite();
        $source->clearKept();
    }

    private static function copyReading(EntryState $source, EntryState $target): void
    {
        if ($source->isHidden() && !$target->isHidden()) {
            $target->restoreReadMark(new BackedUpReadMark(true, $source->getHiddenAt()));
        }
        $viewedAt = $source->getViewedAt();
        if (null !== $viewedAt) {
            $target->markViewed($viewedAt);
        }
    }
}
