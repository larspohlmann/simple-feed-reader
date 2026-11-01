<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\PostWriting;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PostWritingTest extends DbTestCase
{
    use SeedsUsers;

    private const string URL = 'https://example.com/article';

    private FakeMastodonNetwork $mastodon;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('post-writing@example.test')),
        );
    }

    public function testALinkIsSharedByTheAccountsNetwork(): void
    {
        $published = $this->writing()->share($this->account, self::URL, new PostDraftModel('Read this'));

        self::assertSame(['share ' . self::URL . ' Read this'], $this->mastodon->calls);
        self::assertSame(FakeMastodonNetwork::REPLY_URI, $published->address);
    }

    public function testAnAccountThatNeedsReconnectingSharesNothing(): void
    {
        $this->account->markNeedsReconnect();

        try {
            $this->writing()->share($this->account, self::URL, new PostDraftModel('Read this'));
            self::fail('An account that needs reconnecting must not share.');
        } catch (SocialAccountNeedsReconnectException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    private function writing(): PostWriting
    {
        $mastodon = $this->mastodon;

        return new PostWriting(
            new ActingAccountGuard($this->entityManager),
            new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
        );
    }
}
