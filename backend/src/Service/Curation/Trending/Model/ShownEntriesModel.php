<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** The entries of each story a reader is shown, each with the url_hash of the shared link it was grouped by. */
final readonly class ShownEntriesModel
{
    /** @var array<int, ?string> by entry id */
    private array $linkHashes;

    /** @param array<int, array<int, ?string>> $linkHashesByStory story id => entry id => its link's url_hash */
    public function __construct(private array $linkHashesByStory)
    {
        $this->linkHashes = array_replace([], ...array_values($linkHashesByStory));
    }

    /** @return array<int, list<int>> */
    public function entryIdsByStory(): array
    {
        return array_map(array_keys(...), $this->linkHashesByStory);
    }

    /** @return list<int> */
    public function entryIds(): array
    {
        return array_keys($this->linkHashes);
    }

    /**
     * A listed entry's own link hash, null when it has none; a copy the list folded under a listed row, and the
     * snapshot does not list, takes that row's.
     */
    public function resolveLinkHash(int $entryId, int $listedRowId): ?string
    {
        return \array_key_exists($entryId, $this->linkHashes)
            ? $this->linkHashes[$entryId]
            : $this->linkHashes[$listedRowId] ?? null;
    }
}
