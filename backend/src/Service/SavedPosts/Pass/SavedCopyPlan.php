<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Pass;

use App\Entity\EntryGuid;

/**
 * Which of a batch's posts the saved feed already holds. A guid is unique per feed only, so the same guid under
 * another URL is another article: it is stored as `<guid>#<source feed id>`. No two originals of one plan copy to the
 * same guid; the later one is deferred to a plan made once this one's copies exist.
 */
final class SavedCopyPlan
{
    /** @var array<int, int> */
    private array $merged = [];

    /** @var list<int> */
    private array $plain = [];

    /** @var array<int, EntryGuid> */
    private array $renamed = [];

    /** @var list<int> */
    private array $deferred = [];

    /** @var array<string, true> */
    private array $claimedHashes = [];

    /**
     * @param list<array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int}> $originals
     * @param array<string, array{id: int, urlHash: ?string}>                                   $held
     */
    public function __construct(array $originals, array $held)
    {
        foreach ($originals as $original) {
            $this->place($original, $held);
        }
    }

    /**
     * @param list<array{guid: string, guidHash: string, feedId: int}> $originals
     *
     * @return list<string>
     */
    public static function heldHashesToAsk(array $originals): array
    {
        $hashes = [];
        foreach ($originals as $original) {
            $hashes[] = $original['guidHash'];
            $hashes[] = self::renamedGuidOf($original)->getHash();
        }

        return $hashes;
    }

    /** @return array<int, int> */
    public function merged(): array
    {
        return $this->merged;
    }

    /** @return list<int> */
    public function plain(): array
    {
        return $this->plain;
    }

    /** @return array<int, EntryGuid> */
    public function renamed(): array
    {
        return $this->renamed;
    }

    /** @return list<int> */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /**
     * @param array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int} $original
     * @param array<string, array{id: int, urlHash: ?string}>                              $held
     */
    private function place(array $original, array $held): void
    {
        $sameGuid = $held[$original['guidHash']] ?? null;
        if (null === $sameGuid) {
            $this->placePlain($original);

            return;
        }
        if ($sameGuid['urlHash'] === $original['urlHash']) {
            $this->merged[$original['id']] = $sameGuid['id'];

            return;
        }
        $renamedGuid = self::renamedGuidOf($original);
        $renamedHeld = $held[$renamedGuid->getHash()] ?? null;
        if (null !== $renamedHeld) {
            $this->merged[$original['id']] = $renamedHeld['id'];

            return;
        }
        $this->placeRenamed($original['id'], $renamedGuid);
    }

    /** @param array{id: int, guidHash: string} $original */
    private function placePlain(array $original): void
    {
        if ($this->claimOrDefer($original['guidHash'], $original['id'])) {
            $this->plain[] = $original['id'];
        }
    }

    private function placeRenamed(int $entryId, EntryGuid $renamedGuid): void
    {
        if ($this->claimOrDefer($renamedGuid->getHash(), $entryId)) {
            $this->renamed[$entryId] = $renamedGuid;
        }
    }

    private function claimOrDefer(string $targetHash, int $entryId): bool
    {
        if (isset($this->claimedHashes[$targetHash])) {
            $this->deferred[] = $entryId;

            return false;
        }
        $this->claimedHashes[$targetHash] = true;

        return true;
    }

    /** @param array{guid: string, feedId: int} $original */
    private static function renamedGuidOf(array $original): EntryGuid
    {
        return new EntryGuid($original['guid'] . '#' . $original['feedId']);
    }
}
