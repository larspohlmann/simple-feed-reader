<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Social\AuthorFeeds;
use App\Service\Social\Model\HiddenProfileModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SubscriptionHiddenProfileTest extends ApiTestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel(self::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
        $this->user = $this->factory()->create('hidden-profile-api@example.test');
    }

    public function testAHiddenProfileIsSubscribedThroughTheConnectedAccountWithItsTags(): void
    {
        $account = SocialAccounts::blueskyOf($this->user);
        $tag = new Tag($this->user, 'People');
        $tag->setPosition(0);
        $this->entityManager()->persist($account);
        $this->entityManager()->persist($tag);
        $this->entityManager()->flush();

        $this->post(['url' => 'https://bsky.app/profile/rusty-17.bsky.social', 'tagIds' => [$tag->requireId()]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $subscription = $this->payload($this->client)['subscription'] ?? null;
        self::assertIsArray($subscription);
        self::assertSame(
            sprintf('social:bluesky:%d:author:%s', $account->requireId(), self::RUSTY),
            $subscription['feedUrl'],
        );
        self::assertSame('Rusty', $subscription['title']);
        self::assertSame(['network' => 'bluesky', 'accountId' => null, 'kind' => 'profile'], $subscription['social']);
        self::assertTrue($subscription['includeInAllItems']);
        self::assertTrue($subscription['includeInForYou']);
        self::assertIsArray($subscription['tags']);
        self::assertCount(1, $subscription['tags']);
    }

    public function testWithoutAConnectedAccountTheAnswerSaysHowToSubscribe(): void
    {
        $this->post(['url' => 'https://bsky.app/profile/rusty-17.bsky.social/rss']);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = $this->payload($this->client);
        self::assertSame('bluesky_profile_hidden', $problem['type'] ?? null);
        self::assertSame(
            'This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social'
            . ' Media to subscribe.',
            $problem['detail'] ?? null,
        );
    }

    public function testAProfileAlreadyReadThroughAnotherAccountIsAnAlreadySubscribedAnswer(): void
    {
        $default = SocialAccounts::blueskyOf($this->user);
        $default->becomeDefault();
        $other = SocialAccounts::secondBlueskyOf($this->user);
        $this->entityManager()->persist($default);
        $this->entityManager()->persist($other);
        $this->entityManager()->flush();
        $authorFeeds = self::getContainer()->get(AuthorFeeds::class);
        self::assertInstanceOf(AuthorFeeds::class, $authorFeeds);
        $authorFeeds->create($other, new HiddenProfileModel(self::RUSTY, 'Rusty'), []);

        $this->post(['url' => 'https://bsky.app/profile/rusty-17.bsky.social']);

        $this->assertRejected($this->client, Response::HTTP_CONFLICT);
        self::assertSame('already_subscribed', $this->payload($this->client)['type'] ?? null);
        self::assertSame(1, $this->entityManager()->getRepository(Subscription::class)->count([]));
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): void
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $this->client->request(
            'POST',
            '/api/subscriptions',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($this->user),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
