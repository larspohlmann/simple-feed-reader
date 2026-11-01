<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\LinkPosts;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\LinkPostModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\PostWriting;
use App\Service\Social\SocialAccountForUser;
use App\Service\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeComposerDefaults;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class LinkPostsTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string ARTICLE_URL = 'https://news.example/article?id=7';

    private FakeMastodonNetwork $mastodon;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->owner = $this->user('link-posts@example.test');
    }

    public function testTheComposerWritesAsTheNetworksDefaultAccountWithItsOwnDefaults(): void
    {
        $first = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $default = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $default->becomeDefault();
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $this->entityManager->flush();

        $context = $this->linkPosts()->contextFor($this->owner, $this->article(), SocialNetwork::Mastodon, null);

        self::assertSame([$default, $first], $context->accounts);
        self::assertSame($default, $context->account);
        self::assertTrue($context->canSend);
        self::assertEquals(new ComposerDefaultsModel(500, 'public'), $context->defaults);
        self::assertSame([], $this->mastodon->calls);
    }

    public function testTheComposerWritesAsTheChosenAccount(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $chosen = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );

        $context = $this->linkPosts()->contextFor(
            $this->owner,
            $this->article(),
            SocialNetwork::Mastodon,
            $chosen->requireId(),
        );

        self::assertSame($chosen, $context->account);
    }

    public function testALinkPostSharesTheNormalisedArticleAddressAsTheDefaultAccount(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $entry = $this->article(self::ARTICLE_URL . '&utm_source=x');

        $published = $this->linkPosts()->share(
            $this->owner,
            $entry,
            new LinkPostModel(SocialNetwork::Mastodon, null, new PostDraftModel('Worth reading')),
        );

        self::assertSame(['share ' . self::ARTICLE_URL . ' Worth reading'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
    }

    public function testAnAccountOnAnotherNetworkOrOfAnotherUserCannotBeChosen(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $bluesky = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $foreign = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('someone-else@example.test')),
        );
        $entry = $this->article();

        foreach ([$bluesky, $foreign] as $account) {
            $this->assertRefused(
                fn (): mixed => $this->linkPosts()->share(
                    $this->owner,
                    $entry,
                    new LinkPostModel(SocialNetwork::Mastodon, $account->requireId(), new PostDraftModel('Hi')),
                ),
                SocialAccountNotFoundException::class,
                'Only an account of the user on the asked network can post the link.',
            );
        }
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAUserWithoutAnAccountOnTheNetworkCannotPostTheLink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));

        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->linkPosts()->contextFor($this->owner, $this->article(), SocialNetwork::Mastodon, null);
    }

    public function testAnEntryWithoutALinkIsRefusedBeforeAnyAccountIsLookedUp(): void
    {
        $entry = $this->article(null);
        $missingAccountId = 999999;

        $this->assertRefused(
            fn (): mixed => $this->linkPosts()->contextFor(
                $this->owner,
                $entry,
                SocialNetwork::Mastodon,
                $missingAccountId,
            ),
            SocialPostRefusedException::class,
            'The composer for an entry without a link refuses before it looks for the account.',
        );
        $this->assertRefused(
            fn (): mixed => $this->linkPosts()->share(
                $this->owner,
                $entry,
                new LinkPostModel(SocialNetwork::Mastodon, $missingAccountId, new PostDraftModel('Hi')),
            ),
            SocialPostRefusedException::class,
            'An entry without a link is refused before the account is looked for.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAnAddressTheNormaliserRejectsCountsAsNoLink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $this->expectException(SocialPostRefusedException::class);

        $this->linkPosts()->share(
            $this->owner,
            $this->article('not a url'),
            new LinkPostModel(SocialNetwork::Mastodon, null, new PostDraftModel('Hi')),
        );
    }

    public function testAPrivateFeedsEntryIsPostedAsTheAskedNetworksDefaultNotTheFeedsAccount(): void
    {
        $mastodon = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $bluesky = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $entry = $this->entryOn($this->privateFeedOf($bluesky), self::ARTICLE_URL);

        $context = $this->linkPosts()->contextFor($this->owner, $entry, SocialNetwork::Mastodon, null);
        $this->linkPosts()->share(
            $this->owner,
            $entry,
            new LinkPostModel(SocialNetwork::Mastodon, null, new PostDraftModel('Hi')),
        );

        self::assertSame($mastodon, $context->account);
        self::assertSame(['share ' . self::ARTICLE_URL . ' Hi'], $this->mastodon->calls);
    }

    private function article(?string $url = self::ARTICLE_URL): Entry
    {
        $feed = new Feed('https://news.example/feed.xml');

        return $this->entryOn($feed, $url);
    }

    private function privateFeedOf(SocialAccount $account): Feed
    {
        $feed = new Feed('social:bluesky:' . $account->requireId() . ':home');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Home, ''));
        $this->entityManager->flush();

        return $feed;
    }

    private function entryOn(Feed $feed, ?string $url): Entry
    {
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, 'tag:news.example,2026:7', $url, 'An article', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function linkPosts(): LinkPosts
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $mastodon = $this->mastodon;

        return new LinkPosts(
            $accounts,
            new SocialAccountForUser($accounts),
            new UrlNormalizer(),
            new PostWriting(
                new ActingAccountGuard($this->entityManager),
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            ),
            new ServiceLocator(['mastodon' => static fn (): FakeComposerDefaults => new FakeComposerDefaults()]),
        );
    }
}
