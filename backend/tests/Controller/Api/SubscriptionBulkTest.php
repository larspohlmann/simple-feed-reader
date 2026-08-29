<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionBulkTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function makeTag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition(0);
        $this->em()->persist($tag);

        return $tag;
    }

    private function makeSub(User $user, string $url, ?Tag $tag = null): Subscription
    {
        $feed = new Feed($url);
        $this->em()->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        if (null !== $tag) {
            $subscription->addTag($tag, 0);
        }
        $this->em()->persist($subscription);

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function send(KernelBrowser $client, User $user, string $method, string $url, array $body): void
    {
        $client->request(
            $method,
            $url,
            server: $this->headers($user),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('PATCH', '/api/subscriptions/bulk');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAddsATagToEveryListedFeed(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-add@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $first = $this->makeSub($user, 'https://a.example/feed.xml');
        $second = $this->makeSub($user, 'https://b.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $first->getId(), (int) $second->getId()],
            'addTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertIsArray($body['subscriptions']);
        self::assertCount(2, $body['subscriptions']);
        foreach ($body['subscriptions'] as $subscription) {
            self::assertIsArray($subscription);
            self::assertIsArray($subscription['tags']);
            self::assertIsArray($subscription['tags'][0]);
            self::assertSame('Tech', $subscription['tags'][0]['name']);
        }
    }

    public function testTagChangesArePersistedNotJustReturned(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-persist@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $subscription = $this->makeSub($user, 'https://persist.example/feed.xml');
        $this->em()->flush();
        $subscriptionId = (int) $subscription->getId();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$subscriptionId],
            'addTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Subscription::class)->find($subscriptionId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(
            1,
            $reloaded->getTags(),
            'The tag change must be flushed, not only reflected on the response entity.',
        );
        $tags = $reloaded->getTags()->toArray();
        self::assertInstanceOf(Tag::class, $tags[0]);
        self::assertSame('Tech', $tags[0]->getName());
    }

    public function testSetsAnInclusionFlagInTheSameRequest(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-flags@example.com');
        $subscription = $this->makeSub($user, 'https://flags.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $subscription->getId()],
            'includeInAllItems' => false,
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertIsArray($body['subscriptions']);
        self::assertIsArray($body['subscriptions'][0]);
        self::assertFalse($body['subscriptions'][0]['includeInAllItems']);
        self::assertTrue($body['subscriptions'][0]['includeInForYou']);
    }

    public function testRejectsAForeignSubscriptionAndWritesNothing(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-mine@example.com');
        $theirs = $this->user('bulk-endpoint-theirs@example.com');
        $tech = $this->makeTag($mine, 'Tech');
        $ours = $this->makeSub($mine, 'https://ours.example/feed.xml');
        $foreign = $this->makeSub($theirs, 'https://foreign.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $mine, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $ours->getId(), (int) $foreign->getId()],
            'addTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Subscription::class)->find((int) $ours->getId());
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(0, $reloaded->getTags(), 'A rejected bulk request must write nothing.');
    }

    /**
     * Sends a flag alongside the foreign tag and re-reads the subscription
     * afterwards (clearing the entity manager first, same staleness reason as
     * testRejectsAForeignSubscriptionAndWritesNothing). apply() must reject
     * the tag ownership BEFORE it resolves subscriptions or writes flags —
     * reordering assertOwnedTagIds() after resolve()/applyFlags() still
     * returns this same 422, but leaves the flag written, and only a test
     * that re-reads the row can tell those two apart.
     */
    public function testRejectsAForeignTag(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-tag-mine@example.com');
        $theirs = $this->user('bulk-endpoint-tag-theirs@example.com');
        $foreignTag = $this->makeTag($theirs, 'Theirs');
        $ours = $this->makeSub($mine, 'https://ours2.example/feed.xml');
        $this->em()->flush();
        $ourId = (int) $ours->getId();

        $this->send($client, $mine, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$ourId],
            'addTagIds' => [(int) $foreignTag->getId()],
            'includeInAllItems' => false,
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Subscription::class)->find($ourId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(0, $reloaded->getTags(), 'A rejected bulk request must write no tag.');
        self::assertTrue($reloaded->isIncludeInAllItems(), 'A rejected bulk request must write no flag.');
    }

    public function testRejectsMoreIdsThanTheCap(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-cap@example.com');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => range(1, 501),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnsubscribesEveryListedFeedAndKeepsTheRest(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-unsub@example.com');
        $kept = $this->makeSub($user, 'https://kept.example/feed.xml');
        $goingOne = $this->makeSub($user, 'https://going1.example/feed.xml');
        $goingTwo = $this->makeSub($user, 'https://going2.example/feed.xml');
        $this->em()->flush();
        $keptId = (int) $kept->getId();

        $this->send($client, $user, 'POST', '/api/subscriptions/bulk-unsubscribe', [
            'subscriptionIds' => [(int) $goingOne->getId(), (int) $goingTwo->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['removed' => 2], $this->json($client));
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Subscription::class)->find($keptId));
        self::assertNull(
            $this->em()->getRepository(Subscription::class)->find((int) $goingOne->getId()),
            'unsubscribeAll() must actually remove the listed subscription, not just report the count.',
        );
        self::assertNull(
            $this->em()->getRepository(Subscription::class)->find((int) $goingTwo->getId()),
            'unsubscribeAll() must actually remove the listed subscription, not just report the count.',
        );
    }

    public function testUnsubscribeRejectsAForeignIdAndRemovesNothing(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-unsub-mine@example.com');
        $theirs = $this->user('bulk-endpoint-unsub-theirs@example.com');
        $ours = $this->makeSub($mine, 'https://mine.example/feed.xml');
        $foreign = $this->makeSub($theirs, 'https://theirs.example/feed.xml');
        $this->em()->flush();
        $ourId = (int) $ours->getId();

        $this->send($client, $mine, 'POST', '/api/subscriptions/bulk-unsubscribe', [
            'subscriptionIds' => [$ourId, (int) $foreign->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Subscription::class)->find($ourId));
    }
}
