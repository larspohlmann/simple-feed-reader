<?php

declare(strict_types=1);

namespace App\Service\Library\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Repository\EntryShareRepository;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedStateMover
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private EntryShareRepository $entryShares,
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
        $this->moveShares($userId, $targetByEntryId);
        $this->entityManager->flush();
    }

    /** @param non-empty-array<int, int> $targetByEntryId */
    private function moveShares(int $userId, array $targetByEntryId): void
    {
        $sources = $this->entryShares->forUserByEntryIds($userId, array_keys($targetByEntryId));
        if ([] === $sources) {
            return;
        }
        $targets = $this->entryShares->forUserByEntryIds($userId, array_values($targetByEntryId));
        foreach ($sources as $entryId => $source) {
            $targetId = $targetByEntryId[$entryId];
            $targets[$targetId] = $this->shareOnto($source, $targets[$targetId] ?? null, $targetId);
            $this->entityManager->remove($source);
        }
    }

    private function shareOnto(EntryShare $source, ?EntryShare $target, int $targetId): EntryShare
    {
        if (null !== $target) {
            if ($source->getSharedAt() > $target->getSharedAt()) {
                $target->reshare($source->getSharedAt());
            }

            return $target;
        }
        $targetEntry = $this->entityManager->getReference(Entry::class, $targetId)
            ?? throw new \LogicException('A rescued entry has no reference.');
        $moved = new EntryShare($source->getUser(), $targetEntry, $source->getSharedAt());
        $this->entityManager->persist($moved);

        return $moved;
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
