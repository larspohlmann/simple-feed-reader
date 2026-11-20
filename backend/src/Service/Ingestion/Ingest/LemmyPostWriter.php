<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Repository\LemmyPostRepository;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/** Records the community a parsed Lemmy post carries on its item's row, creating it when missing. Run after the entries are linked to their items. */
final readonly class LemmyPostWriter
{
    public function __construct(private LemmyPostRepository $lemmyPosts)
    {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs new entries, or stored ones a refetch served again */
    public function attach(array $pairs): void
    {
        $communities = self::collectCommunities($pairs);
        $stored = $this->lemmyPosts->findByCanonicalItemIds(array_keys($communities));
        $changed = array_filter(
            $communities,
            static fn (PostCommunity $community, int $itemId): bool => !self::isStoredAs(
                $stored[$itemId] ?? null,
                $community,
            ),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->lemmyPosts->storeCommunities($changed);
    }

    private static function isStoredAs(?LemmyPost $row, PostCommunity $community): bool
    {
        $stored = $row?->getCommunity();

        return $stored !== null && $stored->identity === $community->identity && $stored->url === $community->url;
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs
     *
     * @return array<int, PostCommunity> by item id, the last copy's community winning
     */
    private static function collectCommunities(array $pairs): array
    {
        $communities = [];
        foreach ($pairs as [$entry, $parsed]) {
            $itemId = $entry->getCanonicalItemId();
            $community = self::keepIfStorable($parsed->community);
            if ($itemId !== null && $community !== null) {
                $communities[$itemId] = $community;
            }
        }

        return $communities;
    }

    private static function keepIfStorable(?PostCommunity $community): ?PostCommunity
    {
        $fits = $community !== null
            && mb_strlen($community->identity) <= LemmyPost::IDENTITY_MAX_LENGTH
            && mb_strlen($community->url) <= LemmyPost::URL_MAX_LENGTH;

        return $fits ? $community : null;
    }
}
