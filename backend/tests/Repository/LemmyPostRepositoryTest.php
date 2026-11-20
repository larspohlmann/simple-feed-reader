<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CanonicalItem;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Enum\CanonicalItemKind;
use App\Repository\LemmyPostRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;

final class LemmyPostRepositoryTest extends DbTestCase
{
    private LemmyPostRepository $posts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posts = self::getContainer()->get(LemmyPostRepository::class);
    }

    public function testRowsComeKeyedByItemIdAndItemsWithoutOneAreAbsent(): void
    {
        $withRow = $this->persistItem('with');
        $withoutRow = $this->persistItem('without');
        $this->entityManager->persist(new LemmyPost($withRow));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->posts->findByCanonicalItemIds([$withRow->requireId(), $withoutRow->requireId()]);

        self::assertSame([$withRow->requireId()], array_keys($found));
    }

    public function testNoIdsAskNothing(): void
    {
        self::assertSame([], $this->posts->findByCanonicalItemIds([]));
    }

    public function testStoringACommunityTwiceForOneItemKeepsOneRowWithTheLaterCommunity(): void
    {
        $item = $this->persistItem('raced');
        $first = PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology');
        $later = PostCommunity::fromIdentityAndUrl('news@lemmy.world', 'https://lemmy.world/c/news');

        $this->posts->storeCommunity($item->requireId(), $first);
        $this->posts->storeCommunity($item->requireId(), $later);
        $this->entityManager->clear();

        $rows = $this->posts->findAll();
        self::assertCount(1, $rows);
        self::assertEquals($later, $rows[0]->getCommunity());
    }

    public function testRemovingTheItemRemovesItsRow(): void
    {
        $item = $this->persistItem('gone');
        $this->entityManager->persist(new LemmyPost($item));
        $this->entityManager->flush();

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        self::assertSame([], $this->posts->findAll());
    }

    private function persistItem(string $name): CanonicalItem
    {
        $item = CanonicalItems::buildItem(CanonicalItemKind::Lemmy, 'https://lemmy.test/post/' . $name);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }
}
