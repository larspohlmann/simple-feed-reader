<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\SocialAccountForUser;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class SocialAccountForUserTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('account-for-user@example.test');
    }

    public function testAnAccountOnTheAskedNetworkIsReturned(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $found = $this->accountForUser()
            ->requireConnectedOn($this->owner, $account->requireId(), SocialNetwork::Mastodon);

        self::assertSame($account, $found);
    }

    public function testAnAccountOnAnotherNetworkReadsAsMissing(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));

        $this->expectException(SocialAccountNotFoundException::class);
        $this->expectExceptionMessage(sprintf('No mastodon account %d for this account.', $account->requireId()));

        $this->accountForUser()->requireConnectedOn($this->owner, $account->requireId(), SocialNetwork::Mastodon);
    }

    public function testAnAccountNeedingReconnectIsRefused(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        $this->expectException(SocialAccountNeedsReconnectException::class);

        $this->accountForUser()->requireConnectedOn($this->owner, $account->requireId(), SocialNetwork::Mastodon);
    }

    private function accountForUser(): SocialAccountForUser
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);

        return new SocialAccountForUser($accounts);
    }
}
