<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostCountsSource\PostCountsSourceInterface;
use App\Service\Social\PostResolver\PostResolverInterface;
use App\Service\Social\SocialCounts;
use App\Service\Social\SocialCountsCache;
use App\Service\Social\SocialPostFinder;
use App\Service\Social\SocialRepliesSource;
use App\Tests\DbTestCase;
use App\Tests\Support\ConfirmedDeletions;
use App\Tests\Support\FakeHomeServerCheck;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SavedBlueskyPostInteractionTest extends DbTestCase
{
    use SeedsUsers;

    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';

    private User $owner;
    private SocialAccount $account;
    private Entry $savedPost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('saved-bluesky@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $this->savedPost = $this->savedBlueskyPost();
    }

    public function testALikeFindsTheSavedPostOnTheDefaultAccount(): void
    {
        $post = $this->finder()->find($this->owner, $this->savedPost);

        self::assertSame($this->account, $post->account);
        self::assertSame(self::POST_URI, $post->postId);
    }

    public function testTheCountsAreAskedOfTheNetworkByTheOriginalAddress(): void
    {
        $source = new class implements PostCountsSourceInterface {
            /** @var list<string> */
            public array $askedAddresses = [];

            public static function network(): string
            {
                return SocialNetwork::Bluesky->value;
            }

            public function countsOf(string $address): SocialCountsModel
            {
                $this->askedAddresses[] = $address;

                return new SocialCountsModel(3, 2, 1, null);
            }
        };

        $counts = $this->counts($source)->of($this->owner, $this->savedPost);

        self::assertEquals(new SocialCountsModel(3, 2, 1, null), $counts);
        self::assertSame([self::POST_URI], $source->askedAddresses);
    }

    public function testRepliesServeTheSavedPost(): void
    {
        $replies = self::getContainer()->get(SocialRepliesSource::class);
        self::assertInstanceOf(SocialRepliesSource::class, $replies);

        self::assertTrue($replies->serves($this->savedPost));
        $audience = $replies->audienceOf($this->owner, $this->savedPost);
        self::assertSame('bluesky ' . self::POST_URI . ' account:' . $this->account->requireId(), $audience->scope);
        self::assertSame($this->account, $audience->actingAccount);
    }

    public function testTheListRowOffersTheLikeToAUserWithAnAccount(): void
    {
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);
        $row = new EntryListRow(
            $this->savedPost,
            new EntryListRowSubscription(1, 'Saved from Bluesky'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
        );

        $enriched = $enricher->enrich([$row], $this->owner->requireId())[0];

        self::assertEquals(new EntryListRowSocial(SocialNetwork::Bluesky, false, true), $enriched->enrichment->social);
    }

    private function savedBlueskyPost(): Entry
    {
        $feed = new Feed('saved:' . $this->owner->requireId() . ':bluesky');
        $feed->setSourceFormat(SourceFormat::SAVED);
        $feed->classifyAs(SocialNetwork::Bluesky);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-17 10:00:00');
        $entry = new Entry($feed, self::POST_URI, 'https://bsky.app/profile/did:plc:bob/post/3abc', 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function finder(): SocialPostFinder
    {
        $account = $this->account;
        $resolver = new class ($account) implements PostResolverInterface {
            public function __construct(private readonly SocialAccount $account)
            {
            }

            public static function network(): string
            {
                return SocialNetwork::Bluesky->value;
            }

            public function resolve(SocialAccount $account, string $address): SocialPostModel
            {
                return SocialPostModel::onBluesky($this->account, $address, 'bafycid');
            }
        };
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);

        return new SocialPostFinder(
            SocialFeeds::actingAccountChooser($this->entityManager, $accounts),
            new ActingAccountGuard($this->entityManager),
            new ServiceLocator(['bluesky' => static fn (): PostResolverInterface => $resolver]),
        );
    }

    private function counts(PostCountsSourceInterface $source): SocialCounts
    {
        return new SocialCounts(
            new SocialCountsCache(new ArrayAdapter()),
            $this->finder(),
            ConfirmedDeletions::askingOf($this->entityManager, new FakeHomeServerCheck()),
            new ServiceLocator(['bluesky' => static fn (): PostCountsSourceInterface => $source]),
        );
    }
}
