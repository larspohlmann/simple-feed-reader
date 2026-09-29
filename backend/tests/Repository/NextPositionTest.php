<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\NextPosition;
use App\Tests\DbTestCase;
use Doctrine\ORM\QueryBuilder;

final class NextPositionTest extends DbTestCase
{
    public function testAnEmptyListStartsAtZero(): void
    {
        $news = $this->category('next_position_empty');

        self::assertSame(0, $this->nextPosition()->in($this->feedsOf($news)));
    }

    public function testTheNextPositionIsOnePastTheListsHighest(): void
    {
        $news = $this->category('next_position_highest');
        $this->feed($news, 'https://a.next-position.example/feed.xml', 3);
        $this->feed($news, 'https://b.next-position.example/feed.xml', 7);
        $this->entityManager->flush();

        self::assertSame(8, $this->nextPosition()->in($this->feedsOf($news)));
    }

    public function testAnotherListsPositionsDoNotCount(): void
    {
        $news = $this->category('next_position_mine');
        $tech = $this->category('next_position_other');
        $this->feed($tech, 'https://t.next-position.example/feed.xml', 40);
        $this->feed($news, 'https://n.next-position.example/feed.xml', 2);
        $this->entityManager->flush();

        self::assertSame(3, $this->nextPosition()->in($this->feedsOf($news)));
    }

    private function nextPosition(): NextPosition
    {
        $nextPosition = self::getContainer()->get(NextPosition::class);
        self::assertInstanceOf(NextPosition::class, $nextPosition);

        return $nextPosition;
    }

    private function feedsOf(CatalogCategory $category): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(CatalogFeed::class, 'f')
            ->andWhere('f.category = :category')
            ->setParameter('category', $category);
    }

    private function category(string $key): CatalogCategory
    {
        $category = new CatalogCategory($key, $key, 'star', '#000000');
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function feed(CatalogCategory $category, string $url, int $position): void
    {
        $feed = new CatalogFeed($category, 'Title', $url);
        $feed->setPosition($position);
        $this->entityManager->persist($feed);
    }
}
