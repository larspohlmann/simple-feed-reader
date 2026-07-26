<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Feed;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\FeedRepository;
use App\Service\Catalog\CatalogSubscriber;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CatalogSubscriberTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    /** @return array{0: CatalogFeed, 1: CatalogFeed, 2: CatalogFeed} */
    private function catalog(): array
    {
        $technology = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $technology->setPosition(0);
        $science = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $science->setPosition(1);

        $verge = new CatalogFeed($technology, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $ars = new CatalogFeed($technology, 'Ars Technica', 'https://feeds.arstechnica.com/arstechnica/index');
        $quanta = new CatalogFeed($science, 'Quanta Magazine', 'https://api.quantamagazine.org/feed/');

        foreach ([$technology, $science, $verge, $ars, $quanta] as $row) {
            $this->em()->persist($row);
        }
        $this->em()->flush();

        return [$verge, $ars, $quanta];
    }

    private function subscriber(): CatalogSubscriber
    {
        $subscriber = self::getContainer()->get(CatalogSubscriber::class);
        self::assertInstanceOf(CatalogSubscriber::class, $subscriber);

        return $subscriber;
    }

    public function testCreatesOneTagPerCategoryTheUserPickedFrom(): void
    {
        [$verge, $ars, $quanta] = $this->catalog();
        $user = $this->user('picker@example.com');

        $result = $this->subscriber()->subscribe($user, [
            (int) $verge->getId(),
            (int) $ars->getId(),
            (int) $quanta->getId(),
        ]);

        self::assertSame(3, $result->imported);
        self::assertSame(['Technology', 'Science'], array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $result->tagsCreated,
        ));
        self::assertSame('#3b82f6', $result->tagsCreated[0]->getColor());
        self::assertSame('memory', $result->tagsCreated[0]->getIcon());
    }

    public function testACategoryNothingWasPickedFromCreatesNoTag(): void
    {
        [$verge, , ] = $this->catalog();
        $user = $this->user('partial@example.com');

        $result = $this->subscriber()->subscribe($user, [(int) $verge->getId()]);

        self::assertCount(1, $result->tagsCreated);
        self::assertSame('Technology', $result->tagsCreated[0]->getName());
    }

    public function testUnknownAndDisabledIdsAreIgnoredRatherThanFatal(): void
    {
        [$verge, , ] = $this->catalog();
        $disabled = new CatalogFeed($verge->getCategory(), 'Retired', 'https://retired.example.com/rss.xml');
        $disabled->setEnabled(false);
        $this->em()->persist($disabled);
        $this->em()->flush();

        $user = $this->user('stale@example.com');

        $result = $this->subscriber()->subscribe($user, [
            (int) $verge->getId(),
            (int) $disabled->getId(),
            999999,
        ]);

        self::assertSame(1, $result->imported);
    }

    public function testResubmittingTheSameSelectionIsANoOp(): void
    {
        [$verge, , ] = $this->catalog();
        $user = $this->user('repeat@example.com');

        $this->subscriber()->subscribe($user, [(int) $verge->getId()]);
        $second = $this->subscriber()->subscribe($user, [(int) $verge->getId()]);

        self::assertSame(0, $second->imported);
        self::assertSame(1, $second->alreadySubscribed);
        self::assertCount(0, $second->tagsCreated);
    }

    public function testCatalogSourceFormatReachesTheCreatedFeed(): void
    {
        $technology = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $scraped = new CatalogFeed($technology, 'Scraped Blog', 'https://scraped.example.com/index.html');
        $scraped->setSourceFormat(SourceFormat::SCRAPED);

        foreach ([$technology, $scraped] as $row) {
            $this->em()->persist($row);
        }
        $this->em()->flush();

        $user = $this->user('format@example.com');

        $result = $this->subscriber()->subscribe($user, [(int) $scraped->getId()]);
        self::assertSame(1, $result->imported);

        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        $feed = $feeds->findOneBy(['url' => 'https://scraped.example.com/index.html']);
        self::assertInstanceOf(Feed::class, $feed);
        self::assertSame(SourceFormat::SCRAPED, $feed->getSourceFormat());
    }
}
