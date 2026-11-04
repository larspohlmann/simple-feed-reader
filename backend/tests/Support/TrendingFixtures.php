<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\TrendingStory;
use App\Entity\TrendingStoryEntry;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/** Feeds, entries and stored stories for the Trending integration tests; dates are naive UTC strings. */
final readonly class TrendingFixtures
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function feed(string $name, ?SocialNetwork $network = null): Feed
    {
        $feed = new Feed(\sprintf('https://%s.example.com/feed', $name));
        $feed->classifyAs($network);
        $this->entityManager->persist($feed);

        return $feed;
    }

    public function subscribe(User $user, Feed ...$feeds): void
    {
        foreach ($feeds as $feed) {
            $this->entityManager->persist(
                new Subscription($user, $feed, new \DateTimeImmutable('2031-01-01 00:00:00')),
            );
        }
    }

    public function entry(Feed $feed, string $title, string $effectiveDate): Entry
    {
        return $this->persisted(new Entry(
            $feed,
            bin2hex(random_bytes(8)),
            null,
            $title,
            new \DateTimeImmutable($effectiveDate),
            new \DateTimeImmutable($effectiveDate),
        ));
    }

    public function article(Feed $feed, string $title, string $effectiveDate, string $url): Entry
    {
        return $this->persisted(new Entry(
            $feed,
            $url,
            $url,
            $title,
            new \DateTimeImmutable($effectiveDate),
            new \DateTimeImmutable($effectiveDate),
            (new UrlNormalizer())->hash($url),
        ));
    }

    /** Flushes first: a story entry copies its feed's id. */
    public function story(TrendingWindow $window, string $lastSeen, Entry ...$members): TrendingStory
    {
        $this->entityManager->flush();
        $story = new TrendingStory(
            $window,
            new \DateTimeImmutable('2031-05-14 00:00:00'),
            new \DateTimeImmutable($lastSeen),
            \count($members),
        );
        $this->entityManager->persist($story);
        foreach ($members as $member) {
            $this->entityManager->persist(new TrendingStoryEntry($story, $member));
        }
        $this->entityManager->flush();

        return $story;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    private function persisted(Entry $entry): Entry
    {
        $this->entityManager->persist($entry);

        return $entry;
    }
}
