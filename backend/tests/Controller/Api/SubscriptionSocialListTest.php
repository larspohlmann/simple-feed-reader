<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\PrivateFeeds;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

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
        $privateFeeds->create($account, PrivateFeedModel::home());
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        $bearer = 'Bearer ' . $tokens->create($user);

        $client->request('GET', '/api/subscriptions', server: ['HTTP_AUTHORIZATION' => $bearer]);

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
            ]],
            $payload['socialAccounts'],
        );
    }
}
