<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\PostWriting;
use App\Service\Social\ResponseTargetFinder;
use App\Service\Social\SocialAccountForUser;
use App\Service\Social\SocialCountsCache;
use App\Service\Social\SocialPostFinder;
use App\Service\Social\SocialQuotes;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialQuotesTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/200';

    private FakeMastodonNetwork $mastodon;
    private SocialCountsCache $countsCache;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->countsCache = new SocialCountsCache(new ArrayAdapter());
        $this->owner = $this->user('social-quotes@example.test');
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testAQuoteQuotesTheEntrysPostAsTheActingAccountAndDropsItsCachedCounts(): void
    {
        $entry = $this->post();
        $address = new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL);
        $this->countsCache->remember($address, new SocialCountsModel(4, 1, 0, 2));

        $published = $this->quotes()->quote($this->owner, new ResponseTargetModel($entry), new PostDraftModel('Read'));

        self::assertSame(['resolve ' . self::STATUS_URL, 'quote 110 Read'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
        self::assertNull($this->countsCache->find($address));
    }

    public function testAQuoteOfAReplyQuotesThatReply(): void
    {
        $this->quotes()->quote(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            new PostDraftModel('Read'),
        );

        self::assertSame(['resolve ' . self::REPLY_URI, 'quote 110 Read'], $this->mastodon->calls);
    }

    public function testAPostItsAuthorKeepsFromQuotesIsRefusedBeforeTheQuote(): void
    {
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canQuote: false));

        $this->assertRefused(
            fn (): mixed => $this->quotes()->quote(
                $this->owner,
                new ResponseTargetModel($this->post()),
                new PostDraftModel('Read'),
            ),
            SocialPostRefusedException::class,
            'A post its author keeps from quotes cannot be quoted.',
        );
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    private function post(): Entry
    {
        $feed = new Feed('https://mastodon.social/@Gargron.rss');
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function quotes(): SocialQuotes
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);
        $chooser = new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts);
        $posts = new SocialPostFinder($chooser, $guard, $network);

        return new SocialQuotes(
            new ResponseTargetFinder($posts, $chooser, new SocialAccountForUser($accounts)),
            new PostWriting($guard, $network),
            $this->countsCache,
        );
    }
}
