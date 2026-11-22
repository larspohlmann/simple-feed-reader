<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\PostDeletions;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SocialAccounts;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PostDeletionsTest extends TestCase
{
    private FakeMastodonNetwork $mastodon;

    protected function setUp(): void
    {
        $this->mastodon = new FakeMastodonNetwork();
    }

    public function testDeletesThroughTheWriterOfTheAccountsNetwork(): void
    {
        $deletion = $this->deletions()->delete(SocialAccounts::mastodon(), '109');

        self::assertSame(PostDeletion::Deleted, $deletion);
        self::assertSame(['delete 109'], $this->mastodon->calls);
    }

    public function testRefusesAnAccountThatNeedsReconnecting(): void
    {
        $account = SocialAccounts::mastodon();
        $account->markNeedsReconnect();

        try {
            $this->deletions()->delete($account, '109');
            self::fail('An account that needs reconnecting must not delete.');
        } catch (SocialAccountNeedsReconnectException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    private function deletions(): PostDeletions
    {
        $mastodon = $this->mastodon;

        return new PostDeletions(
            new ActingAccountGuard($this->createStub(EntityManagerInterface::class)),
            new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
        );
    }
}
