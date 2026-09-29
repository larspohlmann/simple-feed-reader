<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubscriptionBulkTest extends WebTestCase
{
    use SeedsUsers;

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
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
        $this->entityManager()->persist($tag);

        return $tag;
    }

    private function makeSubscription(User $user, string $url, ?Tag $tag = null): Subscription
    {
        $feed = new Feed($url);
        $this->entityManager()->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        if (null !== $tag) {
            $subscription->addTag($tag, 0);
        }
        $this->entityManager()->persist($subscription);

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
        $first = $this->makeSubscription($user, 'https://a.example/feed.xml');
        $second = $this->makeSubscription($user, 'https://b.example/feed.xml');
        $this->entityManager()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$first->requireId(), $second->requireId()],
            'addTagIds' => [$tech->requireId()],
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
        $subscription = $this->makeSubscription($user, 'https://persist.example/feed.xml');
        $this->entityManager()->flush();
        $subscriptionId = $subscription->requireId();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$subscriptionId],
            'addTagIds' => [$tech->requireId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->getRepository(Subscription::class)->find($subscriptionId);
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
        $subscription = $this->makeSubscription($user, 'https://flags.example/feed.xml');
        $this->entityManager()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$subscription->requireId()],
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
        $ours = $this->makeSubscription($mine, 'https://ours.example/feed.xml');
        $foreign = $this->makeSubscription($theirs, 'https://foreign.example/feed.xml');
        $this->entityManager()->flush();

        $this->send($client, $mine, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$ours->requireId(), $foreign->requireId()],
            'addTagIds' => [$tech->requireId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->getRepository(Subscription::class)->find($ours->requireId());
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(0, $reloaded->getTags(), 'A rejected bulk request must write nothing.');
    }

    /**
     * The flag rides along to prove apply() checks tag ownership before it writes flags: a later check answers the
     * same 422 but leaves the flag written, which only re-reading the row shows.
     */
    public function testRejectsAForeignTag(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-tag-mine@example.com');
        $theirs = $this->user('bulk-endpoint-tag-theirs@example.com');
        $foreignTag = $this->makeTag($theirs, 'Theirs');
        $ours = $this->makeSubscription($mine, 'https://ours2.example/feed.xml');
        $this->entityManager()->flush();
        $ourId = $ours->requireId();

        $this->send($client, $mine, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$ourId],
            'addTagIds' => [$foreignTag->requireId()],
            'includeInAllItems' => false,
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->getRepository(Subscription::class)->find($ourId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(0, $reloaded->getTags(), 'A rejected bulk request must write no tag.');
        self::assertTrue($reloaded->isIncludeInAllItems(), 'A rejected bulk request must write no flag.');
    }

    public function testRejectsMoreIdsThanTheHardCeiling(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-cap@example.com');
        $this->entityManager()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => range(1, SubscriptionService::MAX_BULK_REQUEST_IDS + 1),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnAccountRaisedAboveTheDefaultCapCanBulkActOnAllItsFeeds(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-raised-cap@example.com');
        $user->setMaxSubscriptions(SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 50);
        $count = SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 10;
        $subscriptions = [];
        for ($index = 0; $index < $count; ++$index) {
            $subscriptions[] = $this->makeSubscription($user, "https://raised-cap-$index.example/feed.xml");
        }
        $this->entityManager()->flush();
        $ids = array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $subscriptions);

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => $ids,
            'includeInAllItems' => false,
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertIsArray($body['subscriptions']);
        self::assertCount($count, $body['subscriptions']);
    }

    /**
     * SubscriptionJson::one() reads every subscription's feed and tag joins, so resolveWithAssociations() must load
     * them in one joined read however many subscriptions the response holds.
     */
    public function testSerializingTheBulkResponseCostsOneReadPerAssociationNotOnePerSubscription(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-n1@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $subscriptions = [];
        for ($index = 0; $index < 5; ++$index) {
            $subscriptions[] = $this->makeSubscription($user, "https://n1-{$index}.example/feed.xml", $tech);
        }
        $this->entityManager()->flush();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => array_map(
                static fn (Subscription $subscription): int => $subscription->requireId(),
                $subscriptions,
            ),
            'includeInAllItems' => false,
        ]);

        self::assertResponseIsSuccessful();

        // The eager read joins feed and subscription_tag instead of selecting from them, so match the JOIN clauses.
        $feedReads = $recorder->queriesMatching('join feed');
        self::assertCount(
            1,
            $feedReads,
            "the response's feed lookups must be one batched read, got:\n" . implode("\n", $feedReads),
        );

        $tagJoinReads = $recorder->queriesMatching('join subscription_tag');
        self::assertCount(
            1,
            $tagJoinReads,
            "the response's tag-join lookups must be one batched read, got:\n" . implode("\n", $tagJoinReads),
        );
    }

    /**
     * Two tag reads for any number of feeds: the up-front ownership check and OwnedTagsCache's first lookup in the
     * sync loop, never one per subscription.
     */
    public function testAddingATagAcrossManySubscriptionsCostsOneTagQueryNotOnePerSubscription(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-tag-n1@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $subscriptions = [];
        for ($index = 0; $index < 5; ++$index) {
            $subscriptions[] = $this->makeSubscription($user, "https://tag-n1-{$index}.example/feed.xml");
        }
        $this->entityManager()->flush();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => array_map(
                static fn (Subscription $subscription): int => $subscription->requireId(),
                $subscriptions,
            ),
            'addTagIds' => [$tech->requireId()],
        ]);

        self::assertResponseIsSuccessful();

        $tagReads = $recorder->queriesMatching('from tag');
        self::assertCount(
            2,
            $tagReads,
            "adding one tag across 5 subscriptions must not query the tag table once per "
                . "subscription, got:\n" . implode("\n", $tagReads),
        );
    }

    /**
     * One flush follows the whole loop, so a MAX(position) query per sync() would see none of the earlier feeds'
     * joins and put all three at 0 instead of [0, 1, 2].
     */
    public function testBulkAddTagGivesEachFeedADistinctAscendingTagPosition(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-add-tag-positions@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $first = $this->makeSubscription($user, 'https://pos-a.example/feed.xml');
        $second = $this->makeSubscription($user, 'https://pos-b.example/feed.xml');
        $third = $this->makeSubscription($user, 'https://pos-c.example/feed.xml');
        $this->entityManager()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$first->requireId(), $second->requireId(), $third->requireId()],
            'addTagIds' => [$tech->requireId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        self::assertSame(
            [0, 1, 2],
            [
                $this->joinPosition($first->getId(), $tech->getId()),
                $this->joinPosition($second->getId(), $tech->getId()),
                $this->joinPosition($third->getId(), $tech->getId()),
            ],
        );
    }

    /**
     * The untagged side of the same trap: a MAX() per feed before the single flush would give all three one position.
     */
    public function testBulkRemoveLastTagGivesEachFeedADistinctUntaggedPosition(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-remove-tag-positions@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $first = $this->makeSubscription($user, 'https://untag-a.example/feed.xml', $tech);
        $second = $this->makeSubscription($user, 'https://untag-b.example/feed.xml', $tech);
        $third = $this->makeSubscription($user, 'https://untag-c.example/feed.xml', $tech);
        $this->entityManager()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [$first->requireId(), $second->requireId(), $third->requireId()],
            'removeTagIds' => [$tech->requireId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $positions = [
            $this->subscriptionPosition($first->getId()),
            $this->subscriptionPosition($second->getId()),
            $this->subscriptionPosition($third->getId()),
        ];
        self::assertSame(
            \count($positions),
            \count(array_unique($positions)),
            'positions must be distinct: ' . implode(',', $positions),
        );
    }

    private function joinPosition(?int $subscriptionId, ?int $tagId): int
    {
        $subscription = $this->entityManager()->getRepository(Subscription::class)->find((int) $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag()->requireId() === (int) $tagId) {
                return $join->getPosition();
            }
        }
        self::fail('Subscription is not tagged with tag ' . $tagId);
    }

    private function subscriptionPosition(?int $subscriptionId): int
    {
        $subscription = $this->entityManager()->getRepository(Subscription::class)->find((int) $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription->getPosition();
    }

    public function testUnsubscribesEveryListedFeedAndKeepsTheRest(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-unsub@example.com');
        $kept = $this->makeSubscription($user, 'https://kept.example/feed.xml');
        $goingOne = $this->makeSubscription($user, 'https://going1.example/feed.xml');
        $goingTwo = $this->makeSubscription($user, 'https://going2.example/feed.xml');
        $this->entityManager()->flush();
        $keptId = $kept->requireId();
        $goingOneId = $goingOne->requireId();
        $goingTwoId = $goingTwo->requireId();

        $this->send($client, $user, 'POST', '/api/subscriptions/bulk-unsubscribe', [
            'subscriptionIds' => [$goingOneId, $goingTwoId],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['removed' => 2], $this->json($client));
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->getRepository(Subscription::class)->find($keptId));
        self::assertNull(
            $this->entityManager()->getRepository(Subscription::class)->find($goingOneId),
            'unsubscribeAll() must actually remove the listed subscription, not just report the count.',
        );
        self::assertNull(
            $this->entityManager()->getRepository(Subscription::class)->find($goingTwoId),
            'unsubscribeAll() must actually remove the listed subscription, not just report the count.',
        );
    }

    public function testUnsubscribeRejectsAForeignIdAndRemovesNothing(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-unsub-mine@example.com');
        $theirs = $this->user('bulk-endpoint-unsub-theirs@example.com');
        $ours = $this->makeSubscription($mine, 'https://mine.example/feed.xml');
        $foreign = $this->makeSubscription($theirs, 'https://theirs.example/feed.xml');
        $this->entityManager()->flush();
        $ourId = $ours->requireId();

        $this->send($client, $mine, 'POST', '/api/subscriptions/bulk-unsubscribe', [
            'subscriptionIds' => [$ourId, $foreign->requireId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->getRepository(Subscription::class)->find($ourId));
    }
}
