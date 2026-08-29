<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Support\QueryRecorder;
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

    public function testRejectsMoreIdsThanTheHardCeiling(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-cap@example.com');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => range(1, SubscriptionService::MAX_BULK_REQUEST_IDS + 1),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * An admin-raised per-account limit (SubscriptionLimitResolver) can exceed
     * SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER. A bulk request naming
     * more than the global default, but real, owned subscriptions must not be
     * rejected by a cap that used to equal that default (#659 review).
     */
    public function testAnAccountRaisedAboveTheDefaultCapCanBulkActOnAllItsFeeds(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-raised-cap@example.com');
        $user->setMaxSubscriptions(SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 50);
        $count = SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 10;
        $subscriptions = [];
        for ($i = 0; $i < $count; ++$i) {
            $subscriptions[] = $this->makeSub($user, "https://raised-cap-$i.example/feed.xml");
        }
        $this->em()->flush();
        $ids = array_map(static fn (Subscription $s): int => (int) $s->getId(), $subscriptions);

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
     * SubscriptionJson::one() touches getFeed() (lazy ManyToOne) and
     * getSubscriptionTags() (lazy OneToMany) for every subscription it
     * serializes. findAllByIdsForUser() — what OwnedSubscriptions::resolve()
     * used to route the bulk-update path through — selects with no joins, so
     * serializing N subscriptions cost up to 2N extra SELECTs. The eager
     * resolveWithAssociations() path must keep it at one read per association,
     * however many subscriptions are in the response.
     */
    public function testSerializingTheBulkResponseCostsOneReadPerAssociationNotOnePerSubscription(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-n1@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $subscriptions = [];
        for ($i = 0; $i < 5; ++$i) {
            $subscriptions[] = $this->makeSub($user, "https://n1-{$i}.example/feed.xml", $tech);
        }
        $this->em()->flush();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => array_map(static fn (Subscription $s): int => (int) $s->getId(), $subscriptions),
            'includeInAllItems' => false,
        ]);

        self::assertResponseIsSuccessful();

        // One joined SELECT carries subscription, feed, subscription_tag AND
        // tag together (resolveWithAssociations()) — assert on the JOIN
        // clauses, not a bare "from feed", since feed/subscription_tag never
        // head their own FROM here.
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
     * SubscriptionTagSync::sync() resolves its requested tag ids on every
     * call, and BulkSubscriptionUpdater::apply() calls sync() once per
     * subscription — a naive implementation queries the tag table once per
     * subscription even though every id was already validated once up front
     * (assertOwnedTagIds()). Expect exactly two "from tag" reads: the
     * up-front validation, and the sync loop's first (cache-priming) lookup —
     * never a third for a fifth identical subscription.
     */
    public function testAddingATagAcrossManySubscriptionsCostsOneTagQueryNotOnePerSubscription(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-tag-n1@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $subscriptions = [];
        for ($i = 0; $i < 5; ++$i) {
            $subscriptions[] = $this->makeSub($user, "https://tag-n1-{$i}.example/feed.xml");
        }
        $this->em()->flush();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => array_map(static fn (Subscription $s): int => (int) $s->getId(), $subscriptions),
            'addTagIds' => [(int) $tech->getId()],
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
     * SubscriptionTagSync::sync() picks a new tag's join position with
     * SubscriptionTagRepository::nextPositionForTag(), a MAX(position) query.
     * BulkSubscriptionUpdater::apply() calls sync() once per subscription and
     * flushes only once after the loop, so that query cannot see the rows the
     * earlier iterations just added — every feed reads the same stale MAX and
     * gets the same position. Three untagged feeds tagged in one bulk request
     * must land at distinct ascending positions [0, 1, 2], not all at 0.
     */
    public function testBulkAddTagGivesEachFeedADistinctAscendingTagPosition(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-add-tag-positions@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $first = $this->makeSub($user, 'https://pos-a.example/feed.xml');
        $second = $this->makeSub($user, 'https://pos-b.example/feed.xml');
        $third = $this->makeSub($user, 'https://pos-c.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $first->getId(), (int) $second->getId(), (int) $third->getId()],
            'addTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
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
     * The mirror defect on the untagged side: SubscriptionTagSync::sync()
     * appends a feed that just lost its last tag with
     * SubscriptionRepository::nextPositionForUser(), also a MAX() query before
     * the same single flush. Three feeds stripped of their only tag in one
     * bulk request must land at distinct untagged positions, not all at the
     * same stale MAX.
     */
    public function testBulkRemoveLastTagGivesEachFeedADistinctUntaggedPosition(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-remove-tag-positions@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $first = $this->makeSub($user, 'https://untag-a.example/feed.xml', $tech);
        $second = $this->makeSub($user, 'https://untag-b.example/feed.xml', $tech);
        $third = $this->makeSub($user, 'https://untag-c.example/feed.xml', $tech);
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $first->getId(), (int) $second->getId(), (int) $third->getId()],
            'removeTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
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
        $subscription = $this->em()->getRepository(Subscription::class)->find((int) $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ((int) $join->getTag()->getId() === (int) $tagId) {
                return $join->getPosition();
            }
        }
        self::fail('Subscription is not tagged with tag ' . $tagId);
    }

    private function subscriptionPosition(?int $subscriptionId): int
    {
        $subscription = $this->em()->getRepository(Subscription::class)->find((int) $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription->getPosition();
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
