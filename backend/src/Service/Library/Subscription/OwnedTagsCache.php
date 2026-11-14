<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Caches TagRepository::findAllByIdsForUser() per user, so a bulk write's sync() per subscription costs a map lookup,
 * not a query. reset() empties it between requests: a reused container must not serve a tag bound to a reset
 * EntityManager.
 */
final class OwnedTagsCache implements ResetInterface
{
    /** @var array<int, array<int, Tag>> resolved tag, by id, by owning user id */
    private array $resolvedByUser = [];

    public function __construct(private readonly TagRepository $tags)
    {
    }

    public function reset(): void
    {
        $this->resolvedByUser = [];
    }

    /**
     * @param list<int> $tagIds
     *
     * @return list<Tag>
     */
    public function findAllByIdsForUser(int $userId, array $tagIds): array
    {
        $this->resolveMissing($userId, $tagIds);

        $resolved = $this->resolvedByUser[$userId] ?? [];

        return array_values(array_filter(array_map(
            static fn (int $id): ?Tag => $resolved[$id] ?? null,
            $tagIds,
        )));
    }

    /**
     * @param list<int> $tagIds
     */
    private function resolveMissing(int $userId, array $tagIds): void
    {
        $known = $this->resolvedByUser[$userId] ?? [];
        $missing = array_values(array_unique(
            array_filter($tagIds, static fn (int $id): bool => !isset($known[$id])),
        ));
        if ([] === $missing) {
            return;
        }

        foreach ($this->tags->findAllByIdsForUser($userId, $missing) as $tag) {
            $this->resolvedByUser[$userId][$tag->requireId()] = $tag;
        }
    }
}
