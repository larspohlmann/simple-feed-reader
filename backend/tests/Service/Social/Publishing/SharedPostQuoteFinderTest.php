<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Publishing;

use App\Entity\SocialAccount;
use App\Service\Social\Publishing\SharedPostQuoteFinder;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\SocialRuleWiring;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SharedPostQuoteFinderTest extends DbTestCase
{
    use SeedsUsers;

    public function testALemmyPostUrlIsNeverQuotedByALemmyAccount(): void
    {
        $account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::lemmyOf($this->user('quote-finder@example.test')),
        );

        self::assertNull($this->finder()->findQuotable($account, 'https://lemmy.example/post/42'));
    }

    private function finder(): SharedPostQuoteFinder
    {
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $chooser = SocialFeeds::actingAccountChooser($this->entityManager, $accounts);
        $postAddresses = SocialRuleWiring::postAddresses();
        $guard = new ActingAccountGuard($this->entityManager);

        return new SharedPostQuoteFinder(
            new SocialPostFinder($chooser, $guard, new ServiceLocator([]), $postAddresses),
            $postAddresses,
        );
    }
}
