<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CanonicalItem;
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
        $this->storeCommunity($withRow);
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

        $this->posts->storeCommunities([$item->requireId() => $first]);
        $this->posts->storeCommunities([$item->requireId() => $later]);
        $this->entityManager->clear();

        $rows = $this->posts->findAll();
        self::assertCount(1, $rows);
        self::assertEquals($later, $rows[0]->getCommunity());
    }

    public function testManyItemsAreStoredAcrossStatementsWithEachItsOwnCommunity(): void
    {
        $communities = [];
        foreach (range(1, 120) as $number) {
            $communities[$this->persistItem('many-' . $number)->requireId()] = PostCommunity::fromIdentityAndUrl(
                'c' . $number . '@lemmy.world',
                'https://lemmy.world/c/c' . $number,
            );
        }

        $this->posts->storeCommunities($communities);
        $this->entityManager->clear();

        $found = $this->posts->findByCanonicalItemIds(array_keys($communities));
        self::assertCount(120, $found);
        foreach ($communities as $itemId => $community) {
            self::assertEquals($community, $found[$itemId]->getCommunity());
        }
    }

    public function testRemovingTheItemRemovesItsRow(): void
    {
        $item = $this->persistItem('gone');
        $this->storeCommunity($item);

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        self::assertSame([], $this->posts->findAll());
    }

    private function storeCommunity(CanonicalItem $item): void
    {
        $this->posts->storeCommunities([
            $item->requireId() => PostCommunity::fromIdentityAndUrl(
                'technology@lemmy.world',
                'https://lemmy.world/c/technology',
            ),
        ]);
    }

    private function persistItem(string $name): CanonicalItem
    {
        $item = CanonicalItems::buildItem(CanonicalItemKind::Lemmy, 'https://lemmy.test/post/' . $name);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }
}
