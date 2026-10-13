<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class ActingAccountGuardTest extends DbTestCase
{
    use SeedsUsers;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('acting-guard@example.test')),
        );
    }

    public function testTheCallsAnswerComesBack(): void
    {
        self::assertSame('110', $this->guard()->actAs($this->account, static fn (): string => '110'));
    }

    public function testAnAccountThatNeedsReconnectingIsRefusedBeforeTheCall(): void
    {
        $this->account->markNeedsReconnect();
        $called = false;

        try {
            $this->guard()->actAs($this->account, static function () use (&$called): void {
                $called = true;
            });
            self::fail('An account that needs reconnecting must not act.');
        } catch (SocialAccountNeedsReconnectException) {
            self::assertFalse($called);
        }
    }

    public function testARefusedCredentialMarksTheAccountAndIsRethrown(): void
    {
        $refusal = new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');

        $rethrown = null;
        try {
            $this->guard()->actAs($this->account, static fn (): string => throw $refusal);
        } catch (SocialCredentialsRejectedException $exception) {
            $rethrown = $exception;
        }

        self::assertSame($refusal, $rethrown);

        $accountId = $this->account->requireId();
        $this->entityManager->clear();
        $stored = $this->entityManager->find(SocialAccount::class, $accountId);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $stored?->getStatus());
    }

    private function guard(): ActingAccountGuard
    {
        return new ActingAccountGuard($this->entityManager);
    }
}
