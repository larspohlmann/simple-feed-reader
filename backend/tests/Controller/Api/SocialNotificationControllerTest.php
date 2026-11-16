<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\SocialNotificationPoll;
use App\Entity\User;
use App\Enum\SocialNotificationKind;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialNotificationRows;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SocialNotificationControllerTest extends ApiTestCase
{
    public function testListReturnsGroupsWithActorsCappedAtThree(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-list@example.test');
        $account = $this->persisted(SocialAccounts::blueskyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        for ($index = 0; $index < 4; ++$index) {
            $rows->add($account, SocialNotificationKind::Like, 'at://x/1');
        }
        $newest = $rows->add($account, SocialNotificationKind::Like, 'at://x/1');

        $client->request('GET', '/api/me/notifications?network=bluesky');

        self::assertResponseIsSuccessful();
        $payload = $this->listPayload($client);
        self::assertCount(1, $payload['groups']);
        $group = $payload['groups'][0];
        self::assertIsArray($group['actors']);
        self::assertSame($newest->requireId(), $group['id']);
        self::assertSame('like', $group['kind']);
        self::assertSame('@alice.bsky.social', $group['account']);
        self::assertCount(3, $group['actors']);
        self::assertIsArray($group['actors'][0]);
        self::assertSame(['handle', 'displayName', 'avatarUrl', 'profileUrl'], array_keys($group['actors'][0]));
        self::assertSame(5, $group['actorCount']);
        self::assertSame('excerpt', $group['excerpt']);
        self::assertSame('https://bsky.app/post/at://x/1', $group['linkUrl']);
        self::assertSame('2026-11-16T10:00:00+00:00', $group['latestAt']);
        self::assertFalse($group['seen']);
        self::assertNull($payload['nextCursor']);
        self::assertSame([], $payload['scopeMissing']);
    }

    public function testAFullPageCarriesTheCursorOfItsLastGroup(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-cursor@example.test');
        $account = $this->persisted(SocialAccounts::blueskyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        for ($index = 0; $index < 32; ++$index) {
            $rows->add($account, SocialNotificationKind::Reply, 'at://x/' . $index);
        }

        $client->request('GET', '/api/me/notifications?network=bluesky');
        $first = $this->listPayload($client);
        self::assertCount(30, $first['groups']);
        $cursor = $first['nextCursor'];
        self::assertSame($first['groups'][29]['id'], $cursor);

        $client->request('GET', '/api/me/notifications?network=bluesky&cursor=' . $cursor);
        $second = $this->listPayload($client);
        self::assertCount(2, $second['groups']);
        self::assertNull($second['nextCursor']);
    }

    public function testANetworkWithoutAccountsListsNothing(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-none@example.test');

        $client->request('GET', '/api/me/notifications?network=lemmy');

        self::assertResponseIsSuccessful();
        self::assertSame(['groups' => [], 'nextCursor' => null, 'scopeMissing' => []], $this->payload($client));
    }

    public function testUnknownNetworkIs400(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-unknown@example.test');

        $client->request('GET', '/api/me/notifications?network=friendster');

        $this->assertRejected($client, Response::HTTP_BAD_REQUEST);
    }

    public function testBadCursorIs400(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-bad-cursor@example.test');

        $client->request('GET', '/api/me/notifications?network=bluesky&cursor=abc');

        $this->assertRejected($client, Response::HTTP_BAD_REQUEST);
    }

    public function testSeenReturns204(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-seen@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        $row = $rows->add($account, SocialNotificationKind::Reply, 'c1', true);
        $rowId = $row->requireId();

        $this->postSeen($client, ['network' => 'lemmy', 'upTo' => $rowId]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testUpToOfAnotherUserMarksNothing(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-seen-own@example.test');
        $other = $this->factory()->create('notification-seen-other@example.test');
        $rows = new SocialNotificationRows($this->entityManager());
        $otherRow = $rows->add($this->persisted(SocialAccounts::blueskyOf($other)), SocialNotificationKind::Like, 'a');
        $otherRowId = $otherRow->requireId();
        $this->persisted(SocialAccounts::blueskyAs($user, 'did:plc:mine'));

        $this->postSeen($client, ['network' => 'bluesky', 'upTo' => $otherRowId + 100]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(SocialNotification::class, $otherRowId);
        self::assertNotNull($stored);
        self::assertFalse($stored->isSeen());
    }

    public function testMissingUpToIs422(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-seen-missing@example.test');

        $this->postSeen($client, ['network' => 'bluesky']);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @param array<string, mixed> $body */
    private function postSeen(KernelBrowser $client, array $body): void
    {
        $client->request(
            'POST',
            '/api/me/notifications/seen',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    public function testListsScopeMissingHandles(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-scope@example.test');
        $account = $this->persisted(SocialAccounts::mastodonOf($user));
        $poll = new SocialNotificationPoll($account);
        $poll->recordScopeMissing(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $this->entityManager()->persist($poll);
        $this->entityManager()->flush();

        $client->request('GET', '/api/me/notifications?network=mastodon');

        self::assertResponseIsSuccessful();
        self::assertSame([$account->getHandle()], $this->payload($client)['scopeMissing']);
    }

    public function testListNeedsAToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/me/notifications?network=bluesky');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array{groups: list<array<string, mixed>>, nextCursor: int|null, scopeMissing: list<string>} */
    private function listPayload(KernelBrowser $client): array
    {
        /** @var array{groups: list<array<string, mixed>>, nextCursor: int|null, scopeMissing: list<string>} $payload */
        $payload = $this->payload($client);

        return $payload;
    }

    private function persisted(SocialAccount $account): SocialAccount
    {
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }
}
