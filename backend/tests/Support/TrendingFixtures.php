<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\Subscription;
use App\Entity\TrendingStory;
use App\Entity\TrendingStoryEntry;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\NetworkWithoutFeedsException;
use App\Enum\TrendingWindow;
use App\Service\Foundation\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/** Feeds, entries and stored stories for the Trending integration tests; dates are naive UTC strings. */
final readonly class TrendingFixtures
{
    /** @var list<string> headlines that pairwise share enough stems to link into one story */
    private const array HAFENSTREIK = [
        'Hafenstreik legt Hamburg lahm',
        'Hafenstreik in Hamburg: Terminals lahm',
        'Hamburg: Hafenstreik macht Terminals lahm',
        'Hafenstreik: Hamburg lahm, Terminals zu',
        'Hamburg Terminals lahm: Hafenstreik',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** A social feed's address is one the classifier recognises: the listener re-derives its network on flush. */
    public function feed(string $name, ?SocialNetwork $network = null): Feed
    {
        $feed = new Feed(match ($network) {
            SocialNetwork::Bluesky => \sprintf('https://bsky.app/profile/%s.bsky.social/rss', $name),
            SocialNetwork::Mastodon => \sprintf('https://mastodon.example.com/@%s.rss', $name),
            SocialNetwork::Lemmy => throw NetworkWithoutFeedsException::for(SocialNetwork::Lemmy),
            null => \sprintf('https://%s.example.com/feed', $name),
        });
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

    /** Variants 0 to 4 of one recurring story; any three link, all five make a week-sized story. */
    public function hafenstreik(Feed $feed, int $variant, string $effectiveDate): Entry
    {
        return $this->entry($feed, self::HAFENSTREIK[$variant], $effectiveDate);
    }

    /** A post whose only link to a story is the card carrying $cardUrl; flushes first, a card copies its entry's id. */
    public function postSharing(Feed $feed, string $title, string $effectiveDate, string $cardUrl): Entry
    {
        $post = $this->entry($feed, $title, $effectiveDate);
        $this->entityManager->flush();
        $this->entityManager->persist(new LinkCard(
            $post,
            0,
            new LinkCardContent($cardUrl, 'Card', null, null, (string) parse_url($cardUrl, PHP_URL_HOST)),
        ));

        return $post;
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
