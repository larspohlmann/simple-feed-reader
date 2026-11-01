<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

        $context = $this->linkPosts()->contextFor($this->owner, SocialNetwork::Mastodon, null);

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

        $context = $this->linkPosts()->contextFor($this->owner, SocialNetwork::Mastodon, $chosen->requireId());

        self::assertSame($chosen, $context->account);
    }

    public function testALinkPostSharesTheNormalisedAddressAsTheDefaultAccount(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $published = $this->linkPosts()->share(
            $this->owner,
            self::post(null, self::ARTICLE_URL . '&utm_source=x', 'Worth reading'),
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

        foreach ([$bluesky, $foreign] as $account) {
            $this->assertRefused(
                fn (): mixed => $this->linkPosts()->share($this->owner, self::post($account->requireId())),
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

        $this->linkPosts()->contextFor($this->owner, SocialNetwork::Mastodon, null);
    }

    /** @return iterable<string, array{string}> */
    public static function noLinks(): iterable
    {
        yield 'a blank address' => [''];
        yield 'no address at all' => ['not a url'];
    }

    #[DataProvider('noLinks')]
    public function testAnAddressTheNormaliserRejectsIsRefusedBeforeAnyAccountIsLookedUp(string $url): void
    {
        $missingAccountId = 999999;

        $this->assertRefused(
            fn (): mixed => $this->linkPosts()->share($this->owner, self::post($missingAccountId, $url)),
            SocialPostRefusedException::class,
            'An address that is no link is refused before the account is looked for.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    private static function post(?int $accountId, string $url = self::ARTICLE_URL, string $text = 'Hi'): LinkPostModel
    {
        return new LinkPostModel(SocialNetwork::Mastodon, $accountId, $url, new PostDraftModel($text));
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
