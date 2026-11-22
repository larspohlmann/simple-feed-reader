<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Publishing;

use App\Entity\SocialAccount;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Mastodon\MastodonComposerDefaults;
use App\Service\Social\Publishing\ComposerContexts;
use App\Service\Social\Publishing\Model\ComposerKind;
use App\Service\Social\Publishing\Model\SharedLinkModel;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\SharedPageCards;
use App\Service\Social\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\SocialRuleWiring;
use App\Tests\Support\StubPageCardReader;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ComposerContextsTest extends DbTestCase
{
    use SeedsUsers;

    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const string OWN_ACCOUNT = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';

    public function testAMastodonLinkContextLeavesRoomForTheLinkAndStartsFromTheTitle(): void
    {
        $account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('composer-contexts@example.test')),
        );
        $title = trim(str_repeat('title ', 100));

        $context = $this->contexts()->buildLinkContext($account, new SharedLinkModel('https://news.example/a', $title));

        self::assertSame(475, $context->defaults->maxCharacters);
        self::assertSame($title, $context->text);
    }

    public function testATextPostContextStartsEmptyWithNoSubjectAndTheAccountsOwnDefaults(): void
    {
        $account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('composer-contexts-post@example.test')),
        );

        $context = $this->contexts()->buildTextPostContext($account);

        self::assertSame(ComposerKind::Post, $context->kind);
        self::assertNull($context->subject);
        self::assertSame('', $context->text);
        self::assertTrue($context->canSend);
        self::assertSame(500, $context->defaults->maxCharacters);
        self::assertSame('public', $context->defaults->visibility);
        self::assertSame([$account], $context->accounts);
    }

    private function contexts(): ComposerContexts
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $http = new RoutedHttpClient([
            self::INSTANCE => new MockResponse(json_encode(
                ['configuration' => ['statuses' => ['max_characters' => 500]]],
                \JSON_THROW_ON_ERROR,
            )),
            self::OWN_ACCOUNT => new MockResponse('{"id": "1", "source": {"privacy": "public"}}'),
        ]);
        $mastodonDefaults = new MastodonComposerDefaults(
            SocialApiWiring::mastodonAccountApi($http->client()),
            SocialApiWiring::mastodonApiClient($http->client()),
            SocialApiWiring::mastodonStatusLimit($http->client()),
        );
        $postAddresses = SocialRuleWiring::postAddresses();
        $posts = new SocialPostFinder(
            SocialFeeds::actingAccountChooser($this->entityManager, $accounts),
            new ActingAccountGuard($this->entityManager),
            new ServiceLocator([]),
            $postAddresses,
        );

        return new ComposerContexts(
            $accounts,
            $posts,
            new SharedPageCards(new StubPageCardReader(), new NullLogger()),
            new ServiceLocator(['mastodon' => static fn (): MastodonComposerDefaults => $mastodonDefaults]),
            $postAddresses,
        );
    }
}
