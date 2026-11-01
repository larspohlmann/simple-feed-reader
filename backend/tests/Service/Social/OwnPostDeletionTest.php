<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Entity\User;
use App\Repository\PostRemovalRepository;
use App\Repository\RowIds;
use App\Repository\SocialAccountRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\LocalPostRemoval;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\OwnPostDeletion;
use App\Service\Social\PostWriting;
use App\Service\Social\SocialCountsCache;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class OwnPostDeletionTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.example/@alice/120';

    private FakeMastodonNetwork $mastodon;
    private DeletedPostTombstones $tombstones;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->tombstones = new DeletedPostTombstones(new ArrayAdapter(), new MockClock('2026-11-01 12:00:00'));
        $this->owner = $this->user('own-post@example.test');
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testTheRepliesGoNewestFirstThenThePostThenItsEntryHere(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://mastodon.example/@alice/121');
        $this->reply($post, 2, 'https://mastodon.example/@alice/122');
        $postId = $post->requireId();
        $feedId = $post->getFeed()->requireId();

        $deletion = $this->deletion()->delete($this->owner, $post);

        self::assertSame(PostDeletion::Deleted, $deletion);
        self::assertSame(['delete 122', 'delete 121', 'delete 120'], $this->mastodon->calls);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Entry::class, $postId));
        self::assertSame([EntryGuid::hashOf(self::STATUS_URL)], $this->tombstones->buriedIn($feedId));
    }

    public function testAPostTheNetworkNoLongerHasIsStillRemovedHereAndSaysSo(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $postId = $post->requireId();
        $this->mastodon->knowsGone('120');

        self::assertSame(PostDeletion::AlreadyGone, $this->deletion()->delete($this->owner, $post));
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Entry::class, $postId));
    }

    public function testAReplyAlreadyGoneStillLetsThePostAndItsEntryGo(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://mastodon.example/@alice/121');
        $postId = $post->requireId();
        $this->mastodon->knowsGone('121');

        self::assertSame(PostDeletion::Deleted, $this->deletion()->delete($this->owner, $post));
        self::assertSame(['delete 121', 'delete 120'], $this->mastodon->calls);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Entry::class, $postId));
    }

    public function testAFailedReplyStopsBeforeThePostAndKeepsTheEntry(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://mastodon.example/@alice/121');
        $postId = $post->requireId();
        $this->mastodon->failWith(new SocialNetworkUnreachableException('The instance is down.'));

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialNetworkUnreachableException::class,
            'A failed reply delete must stop the whole delete.',
        );

        self::assertSame(['delete 121'], $this->mastodon->calls);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Entry::class, $postId));
    }

    public function testSomeoneElsesPostIsRefusedBeforeAnyCall(): void
    {
        $post = $this->post('https://mastodon.example/@bob.rss', 'https://mastodon.example/@bob/7');

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialPostRefusedException::class,
            'Only a post a connected account wrote can be deleted.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAReplyOffTheAccountsServerRefusesTheWholeDeleteBeforeAnyCall(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://elsewhere.example/@alice/9');

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialPostRefusedException::class,
            'A thread whose reply cannot be named must not be half-deleted.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAReplyWithoutAnAddressRefusesTheWholeDeleteBeforeAnyCall(): void
    {
        $post = $this->post('https://mastodon.example/@alice.rss', self::STATUS_URL);
        $this->reply($post, 1, 'https://mastodon.example/@alice/121');
        $this->reply($post, 2, null);

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $post),
            SocialPostRefusedException::class,
            'A thread with a reply that has no address must not be half-deleted.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    private function deletion(): OwnPostDeletion
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        /** @var ThreadPostRepository $threadPosts */
        $threadPosts = $this->entityManager->getRepository(ThreadPost::class);
        $mastodon = $this->mastodon;

        return new OwnPostDeletion(
            $accounts,
            $threadPosts,
            new PostWriting(
                new ActingAccountGuard($this->entityManager),
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            ),
            new LocalPostRemoval(
                new PostRemovalRepository($this->entityManager, new RowIds($this->entityManager)),
                $this->tombstones,
                EntryIndexers::with(new RecordingSearchIndexWriter()),
                new SocialCountsCache(new ArrayAdapter()),
            ),
        );
    }

    private function post(string $feedUrl, string $statusUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $statusUrl, $statusUrl, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function reply(Entry $post, int $position, ?string $url): void
    {
        $content = new ThreadPostContent($url, null, '<p>…</p>', []);
        $this->entityManager->persist(new ThreadPost($post, $position, $content));
        $this->entityManager->flush();
    }
}
