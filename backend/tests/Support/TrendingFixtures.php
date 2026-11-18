<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Entity\Subscription;
use App\Entity\TrendingStory;
use App\Entity\TrendingStoryEntry;
use App\Entity\TrendingStoryFollow;
use App\Entity\TrendingStoryHide;
use App\Entity\User;
use App\Enum\ShareKind;
use App\Enum\SocialNetwork;
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
            SocialNetwork::Lemmy => \sprintf('https://lemmy.example.com/feeds/c/%s.xml', $name),
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
            SharedLinks::of(
                $this->entityManager,
                new LinkCardContent($cardUrl, 'Card', null, null, (string) parse_url($cardUrl, PHP_URL_HOST)),
            ),
        ));

        return $post;
    }

    /** A post sharing a link, with its card; flushes first, a card copies its entry's id. */
    public function linkShare(Feed $feed, string $effectiveDate, LinkCardContent $card): Entry
    {
        $post = $this->entry($feed, 'Worth a read', $effectiveDate);
        $post->setShareKind(ShareKind::Link);
        $this->entityManager->flush();
        $this->entityManager->persist(new LinkCard($post, 0, SharedLinks::of($this->entityManager, $card)));

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

    /** Flushes first: a story entry copies its feed's id. Every member joined at $lastSeen and is placed in $window. */
    public function story(TrendingWindow $window, string $lastSeen, Entry ...$members): TrendingStory
    {
        return $this->storyOfSites(
            $window,
            $lastSeen,
            array_map(static fn (Entry $member): array => [$member, null], array_values($members)),
        );
    }

    /**
     * Flushes first: a story entry copies its feed's id. Every member joined at $lastSeen and is placed in $window.
     *
     * @param list<array{Entry, ?string}> $members each entry with its article site
     */
    public function storyOfSites(TrendingWindow $window, string $lastSeen, array $members): TrendingStory
    {
        $this->entityManager->flush();
        $joinedAt = new \DateTimeImmutable($lastSeen);
        $story = new TrendingStory(
            $this->owner(),
            new \DateTimeImmutable('2031-05-14 00:00:00'),
            $joinedAt,
            $joinedAt,
        );
        $this->entityManager->persist($story);
        foreach ($members as [$member, $articleSite]) {
            $this->entityManager->persist(
                new TrendingStoryEntry($story, $member, $articleSite, $this->firstLinkOf($member), $joinedAt),
            );
        }
        $this->place($window, $story, ...array_column($members, 0));

        return $story;
    }

    /** Flushes first: the new members join the story at $joinedAt, placed in no window. */
    public function join(TrendingStory $story, string $joinedAt, Entry ...$members): void
    {
        $this->entityManager->flush();
        foreach ($members as $member) {
            $this->entityManager->persist(new TrendingStoryEntry(
                $story,
                $member,
                null,
                $this->firstLinkOf($member),
                new \DateTimeImmutable($joinedAt),
            ));
        }
        $this->entityManager->flush();
    }

    /** Places members the story already holds in one more window, moving them off any story it showed them in. */
    public function place(TrendingWindow $window, TrendingStory $story, Entry ...$members): void
    {
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        $ownerId = $this->owner()->requireId();
        foreach ($members as $member) {
            $connection->delete(
                'trending_story_placement',
                ['user_id' => $ownerId, 'time_window' => $window->value, 'entry_id' => $member->requireId()],
            );
            $connection->insert('trending_story_placement', [
                'user_id' => $ownerId,
                'time_window' => $window->value,
                'entry_id' => $member->requireId(),
                'story_id' => $story->requireId(),
            ]);
        }
    }

    /** The reader every story of these fixtures belongs to. */
    public function owner(): User
    {
        $email = 'trending-fixtures-owner@example.com';

        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])
            ?? $this->reader($email);
    }

    public function reader(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /** The reader last opened the story when they followed it. */
    public function follow(User $user, TrendingStory $story, string $followedAt): void
    {
        $this->entityManager->persist(new TrendingStoryFollow($user, $story, new \DateTimeImmutable($followedAt)));
        $this->entityManager->flush();
    }

    public function hide(User $user, TrendingStory $story): void
    {
        $this->entityManager->persist(
            new TrendingStoryHide($user, $story, new \DateTimeImmutable('2031-05-14 12:00:00')),
        );
        $this->entityManager->flush();
    }

    private function firstLinkOf(Entry $entry): ?SharedLink
    {
        $card = $this->entityManager->getRepository(LinkCard::class)
            ->findOneBy(['entry' => $entry, 'threadPost' => null], ['position' => 'ASC']);

        return $card?->getLink();
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
