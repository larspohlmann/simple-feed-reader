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
        foreach (self::collectCommunities($pairs) as $itemId => $community) {
            $this->lemmyPosts->storeCommunity($itemId, $community);
        }
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
            $item = $entry->getCanonicalItem();
            $community = self::keepIfStorable($parsed->community);
            if ($item !== null && $community !== null) {
                $communities[$item->requireId()] = $community;
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
