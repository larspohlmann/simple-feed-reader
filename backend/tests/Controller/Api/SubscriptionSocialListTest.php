<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\PrivateFeeds;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;

final class SubscriptionSocialListTest extends ApiTestCase
{
    public function testTheListMarksAPrivateFeedAndNamesItsAccount(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('social-list@example.test');
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();
        $stranger = $this->factory()->create('social-list-stranger@example.test');
        $this->entityManager()->persist(SocialAccounts::mastodonOf($stranger));
        $this->entityManager()->flush();
        $privateFeeds = self::getContainer()->get(PrivateFeeds::class);
        self::assertInstanceOf(PrivateFeeds::class, $privateFeeds);
        $privateFeeds->create($account, PrivateFeedModel::timeline(SocialFeedKind::Home));

        $client->request('GET', '/api/subscriptions', server: $this->bearerFor($user));

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertIsArray($payload['subscriptions']);
        $home = $payload['subscriptions'][0];
        self::assertIsArray($home);
        self::assertSame(
            ['network' => 'bluesky', 'accountId' => $account->requireId(), 'kind' => 'home'],
            $home['social'],
        );
        self::assertFalse($home['includeInAllItems']);
        self::assertSame(
            [[
                'id' => $account->requireId(),
                'network' => 'bluesky',
                'handle' => '@alice.bsky.social',
                'status' => 'connected',
                'serviceUrl' => $account->getServiceUrl(),
            ]],
            $payload['socialAccounts'],
        );
    }

    public function testTheDefaultMastodonAccountComesFirst(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('social-list-default@example.test');
        $earlier = SocialAccounts::mastodonAs($user, 'alice@mastodon.example');
        $later = SocialAccounts::mastodonAs($user, 'alice@other.example');
        $later->becomeDefault();
        $this->entityManager()->persist($earlier);
        $this->entityManager()->persist($later);
        $this->entityManager()->flush();

        $client->request('GET', '/api/subscriptions', server: $this->bearerFor($user));

        self::assertResponseIsSuccessful();
        $accounts = $this->payload($client)['socialAccounts'];
        self::assertIsArray($accounts);
        self::assertSame([$later->requireId(), $earlier->requireId()], array_column($accounts, 'id'));
    }

    public function testTheListNamesTheReadersLemmyHosts(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('social-list-lemmy@example.test');
        $this->entityManager()->persist(SocialAccounts::lemmyOf($user, 'lemmy.example'));
        $this->entityManager()->flush();
        $stranger = $this->factory()->create('social-list-lemmy-stranger@example.test');
        $this->entityManager()->persist(SocialAccounts::lemmyOf($stranger, 'feddit.example'));
        $this->entityManager()->flush();

        $client->request('GET', '/api/subscriptions', server: $this->bearerFor($user));

        self::assertResponseIsSuccessful();
        self::assertSame(['lemmy.example'], $this->payload($client)['lemmyHosts']);
    }

    public function testAReaderWithoutLemmyDataNamesNoLemmyHosts(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('social-list-no-lemmy@example.test');

        $client->request('GET', '/api/subscriptions', server: $this->bearerFor($user));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($client)['lemmyHosts']);
    }
}
