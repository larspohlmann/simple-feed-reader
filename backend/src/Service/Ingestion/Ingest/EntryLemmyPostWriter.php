<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\PostCommunity;
use App\Repository\EntryLemmyPostRepository;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use Doctrine\ORM\EntityManagerInterface;

/** Records the community and score a parsed Lemmy post carries, creating its row when missing. The caller flushes. */
final readonly class EntryLemmyPostWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryLemmyPostRepository $lemmyPosts,
    ) {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs */
    public function attach(array $pairs): void
    {
        foreach ($pairs as [$entry, $parsed]) {
            $this->write($entry, $parsed, null);
        }
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs entries that already have an id */
    public function refresh(array $pairs): void
    {
        $stored = $this->lemmyPosts->byEntryIds(array_map(
            static fn (array $pair): int => $pair[0]->requireId(),
            $pairs,
        ));
        foreach ($pairs as [$entry, $parsed]) {
            $entryId = $entry->requireId();
            $post = $this->write($entry, $parsed, $stored[$entryId] ?? null);
            if ($post !== null) {
                $stored[$entryId] = $post;
            }
        }
    }

    private function write(Entry $entry, ParsedEntryModel $parsed, ?EntryLemmyPost $stored): ?EntryLemmyPost
    {
        $community = self::storable($parsed->community);
        if ($community === null && $parsed->discussionCounts->score === null) {
            return $stored;
        }
        $post = $stored ?? $this->created($entry);
        $post->recordScore($parsed->discussionCounts->score);
        if ($community !== null) {
            $post->placeIn($community);
        }

        return $post;
    }

    private function created(Entry $entry): EntryLemmyPost
    {
        $post = new EntryLemmyPost($entry);
        $this->entityManager->persist($post);

        return $post;
    }

    private static function storable(?PostCommunity $community): ?PostCommunity
    {
        $fits = $community !== null
            && mb_strlen($community->identity) <= EntryLemmyPost::IDENTITY_MAX_LENGTH
            && mb_strlen($community->url) <= EntryLemmyPost::URL_MAX_LENGTH;

        return $fits ? $community : null;
    }
}
