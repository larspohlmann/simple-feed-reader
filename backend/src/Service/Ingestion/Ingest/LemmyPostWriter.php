<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Repository\LemmyPostRepository;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use Doctrine\ORM\EntityManagerInterface;

/** Records the community a parsed Lemmy post carries on its item's row, creating it when missing. Run after the entries are linked to their items. The caller flushes. */
final readonly class LemmyPostWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LemmyPostRepository $lemmyPosts,
    ) {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs new entries, or stored ones a refetch served again */
    public function attach(array $pairs): void
    {
        $placing = self::collectPlacements($pairs);
        if ($placing === []) {
            return;
        }
        $stored = $this->lemmyPosts->findByCanonicalItemIds(array_keys($placing));
        foreach ($placing as $itemId => [$item, $community]) {
            $stored[$itemId] ??= $this->createPost($item);
            $stored[$itemId]->placeIn($community);
        }
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs
     *
     * @return array<int, array{0: CanonicalItem, 1: PostCommunity}> by item id, the last copy's community winning
     */
    private static function collectPlacements(array $pairs): array
    {
        $placing = [];
        foreach ($pairs as [$entry, $parsed]) {
            $item = $entry->getCanonicalItem();
            $community = self::keepIfStorable($parsed->community);
            if ($item !== null && $community !== null) {
                $placing[$item->requireId()] = [$item, $community];
            }
        }

        return $placing;
    }

    private function createPost(CanonicalItem $item): LemmyPost
    {
        $post = new LemmyPost($item);
        $this->entityManager->persist($post);

        return $post;
    }

    private static function keepIfStorable(?PostCommunity $community): ?PostCommunity
    {
        $fits = $community !== null
            && mb_strlen($community->identity) <= LemmyPost::IDENTITY_MAX_LENGTH
            && mb_strlen($community->url) <= LemmyPost::URL_MAX_LENGTH;

        return $fits ? $community : null;
    }
}
