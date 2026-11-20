<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CanonicalItem;
use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Enum\CanonicalItemKind;
use App\Enum\CommentsLoad;
use Doctrine\ORM\EntityManagerInterface;

/** An entry for a Lemmy post, which the caller persists when its test needs a row. */
final class LemmyPostEntries
{
    public const string PAGE = 'https://lemmy.example/post/42';

    private function __construct()
    {
    }

    public static function buildEntry(
        Feed $feed,
        string $page = self::PAGE,
        ?string $guid = null,
        string $publishedAt = '2026-11-14T00:00:00Z',
    ): Entry {
        $published = new \DateTimeImmutable($publishedAt);
        $entry = new Entry($feed, $guid ?? $page, null, 'A post title', $published, $published);
        $entry->setDiscussion(Discussion::fromPageAndFeed($page, null, CommentsLoad::Auto));

        return $entry;
    }

    /**
     * The post's copy in each feed, linked to one item and persisted; the caller flushes.
     *
     * @return array{Entry, Entry}
     */
    public static function persistCopies(EntityManagerInterface $entityManager, Feed $first, Feed $second): array
    {
        $firstCopy = self::buildEntry($first);
        $item = self::linkItem($firstCopy);
        $secondCopy = self::buildEntry($second);
        $secondCopy->linkTo($item);
        $entityManager->persist($item);
        $entityManager->persist($firstCopy);
        $entityManager->persist($secondCopy);

        return [$firstCopy, $secondCopy];
    }

    /** The item a Lemmy post's copies share, linked to the entry; the caller persists it. */
    public static function linkItem(Entry $entry, string $page = self::PAGE): CanonicalItem
    {
        $item = CanonicalItems::buildItem(CanonicalItemKind::Lemmy, $page);
        $entry->linkTo($item);

        return $item;
    }

    /** Flushes, then stores the community on the item's row through the repository. */
    public static function storeCommunity(
        EntityManagerInterface $entityManager,
        CanonicalItem $item,
        PostCommunity $community,
    ): void {
        $entityManager->flush();
        $entityManager->getRepository(LemmyPost::class)->storeCommunity($item->requireId(), $community);
    }
}
