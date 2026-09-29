<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\SeedsUsers;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReorderTest extends WebTestCase
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

    private function makeTag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->entityManager()->persist($tag);

        return $tag;
    }

    private function makeSub(
        User $user,
        string $url,
        int $position,
        ?Tag $tag = null,
        int $tagPosition = 0,
    ): Subscription {
        $feed = new Feed($url);
        $this->entityManager()->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->setPosition($position);
        if (null !== $tag) {
            $subscription->addTag($tag, $tagPosition);
        }
        $this->entityManager()->persist($subscription);

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function patch(KernelBrowser $client, User $user, string $url, array $body): void
    {
        $client->request(
            'PATCH',
            $url,
            server: $this->headers($user),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<int, int> tagId => position, from GET /api/tags */
    private function tagPositions(KernelBrowser $client, User $user): array
    {
        $client->request('GET', '/api/tags', server: $this->headers($user));
        $responseBody = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($responseBody);
        self::assertIsArray($responseBody['tags']);
        $out = [];
        foreach ($responseBody['tags'] as $tag) {
            self::assertIsArray($tag);
            self::assertIsInt($tag['id']);
            self::assertIsInt($tag['position']);
            $out[$tag['id']] = $tag['position'];
        }

        return $out;
    }

    public function testReorderTagsPersistsNewOrder(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-tags@example.com');
        $alpha = $this->makeTag($user, 'Alpha', 0);
        $beta = $this->makeTag($user, 'Beta', 1);
        $gamma = $this->makeTag($user, 'Gamma', 2);
        $this->entityManager()->flush();

        // New order: Gamma, Alpha, Beta.
        $this->patch(
            $client,
            $user,
            '/api/tags/reorder',
            ['tagIds' => [$gamma->getId(), $alpha->getId(), $beta->getId()]],
        );
        self::assertResponseIsSuccessful();

        $positions = $this->tagPositions($client, $user);
        self::assertSame(0, $positions[$gamma->requireId()]);
        self::assertSame(1, $positions[$alpha->requireId()]);
        self::assertSame(2, $positions[$beta->requireId()]);
    }

    /**
     * testReorderTagsPersistsNewOrder re-reads through a second HTTP request
     * on the SAME client, which this suite's own KernelBrowser does not
     * reboot between requests within one test — so that assertion is
     * satisfied by Doctrine's identity map serving the still-attached, merely
     * in-memory-mutated Tag entities, whether or not flush() actually ran.
     * Only a read that goes around the identity map — em->clear() first, like
     * the unsubscribe tests elsewhere in this suite — can tell "persisted"
     * apart from "changed in memory, never written".
     */
    public function testReorderTagsPersistsNewOrderToTheDatabaseNotJustTheEntityManager(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-tags-db@example.com');
        $alpha = $this->makeTag($user, 'Alpha', 0);
        $beta = $this->makeTag($user, 'Beta', 1);
        $gamma = $this->makeTag($user, 'Gamma', 2);
        $this->entityManager()->flush();

        $this->patch(
            $client,
            $user,
            '/api/tags/reorder',
            ['tagIds' => [$gamma->getId(), $alpha->getId(), $beta->getId()]],
        );
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $reload = fn (int $id): Tag
            => $this->entityManager()->getRepository(Tag::class)->find($id) ?? self::fail("tag $id gone");
        self::assertSame(0, $reload($gamma->requireId())->getPosition());
        self::assertSame(1, $reload($alpha->requireId())->getPosition());
        self::assertSame(2, $reload($beta->requireId())->getPosition());
    }

    /**
     * The PATCH response itself must carry the reordered tags — the other
     * tests here only ever check status codes or re-read via a fresh
     * request, so nothing pins the response BODY reorder() actually builds.
     */
    public function testReorderTagsResponseCarriesTheReorderedTags(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-tags-response@example.com');
        $alpha = $this->makeTag($user, 'Alpha', 0);
        $beta = $this->makeTag($user, 'Beta', 1);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$beta->getId(), $alpha->getId()]]);
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['tags']);
        self::assertCount(2, $body['tags']);
        self::assertIsArray($body['tags'][0]);
        self::assertSame('Beta', $body['tags'][0]['name']);
        self::assertSame(0, $body['tags'][0]['position']);
        self::assertIsArray($body['tags'][1]);
        self::assertSame('Alpha', $body['tags'][1]['name']);
        self::assertSame(1, $body['tags'][1]['position']);
    }

    public function testReorderTagsRejectsIncompleteSet(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-partial@example.com');
        $alpha = $this->makeTag($user, 'Alpha', 0);
        $this->makeTag($user, 'Beta', 1);
        $this->entityManager()->flush();

        // Missing Beta → ambiguous → 422.
        $this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$alpha->getId()]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReorderTagsCannotTouchAnotherUsersTag(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-owner@example.com');
        $stranger = $this->user('reorder-stranger@example.com');
        $mine = $this->makeTag($user, 'Mine', 0);
        $theirs = $this->makeTag($stranger, 'Theirs', 0);
        $this->entityManager()->flush();

        // A foreign id is not in the owner's set → 422, no cross-tenant write.
        $this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$mine->getId(), $theirs->getId()]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReorderUntaggedSubscriptions(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-feeds@example.com');
        $firstSubscription = $this->makeSub($user, 'https://f/1', 0);
        $secondSubscription = $this->makeSub($user, 'https://f/2', 1);
        $thirdSubscription = $this->makeSub($user, 'https://f/3', 2);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/subscriptions/reorder', [
            'subscriptionIds' => [
                $thirdSubscription->getId(),
                $firstSubscription->getId(),
                $secondSubscription->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager()->clear();
        $reload = function (int $id): Subscription {
            $subscription = $this->entityManager()->find(Subscription::class, $id);
            self::assertInstanceOf(Subscription::class, $subscription);

            return $subscription;
        };
        self::assertSame(0, $reload($thirdSubscription->requireId())->getPosition());
        self::assertSame(1, $reload($firstSubscription->requireId())->getPosition());
        self::assertSame(2, $reload($secondSubscription->requireId())->getPosition());
    }

    public function testFeedOrderWithinTagPersistsPerTagPosition(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-in-tag@example.com');
        $tag = $this->makeTag($user, 'Tech', 0);
        $firstSubscription = $this->makeSub($user, 'https://f/1', 0, $tag, 0);
        $secondSubscription = $this->makeSub($user, 'https://f/2', 1, $tag, 1);
        $thirdSubscription = $this->makeSub($user, 'https://f/3', 2, $tag, 2);
        $this->entityManager()->flush();

        // New within-tag order: s3, s1, s2.
        $this->patch($client, $user, '/api/tags/' . $tag->getId() . '/feed-order', [
            'subscriptionIds' => [
                $thirdSubscription->getId(),
                $firstSubscription->getId(),
                $secondSubscription->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        // The embedded tag position on each subscription is the per-tag order.
        $client->request('GET', '/api/subscriptions', server: $this->headers($user));
        $responseBody = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($responseBody);
        self::assertIsArray($responseBody['subscriptions']);
        $perTag = [];
        foreach ($responseBody['subscriptions'] as $subscription) {
            self::assertIsArray($subscription);
            self::assertIsArray($subscription['tags']);
            self::assertIsArray($subscription['tags'][0]);
            self::assertIsInt($subscription['id']);
            self::assertIsInt($subscription['tags'][0]['position']);
            $perTag[$subscription['id']] = $subscription['tags'][0]['position'];
        }
        self::assertSame(0, $perTag[$thirdSubscription->requireId()]);
        self::assertSame(1, $perTag[$firstSubscription->requireId()]);
        self::assertSame(2, $perTag[$secondSubscription->requireId()]);
    }

    /**
     * Same identity-map trap as
     * testReorderTagsPersistsNewOrderToTheDatabaseNotJustTheEntityManager:
     * re-reading through a second request on the same client would be
     * satisfied by the still-attached, merely in-memory-mutated join rows
     * even if feedOrder() never flushed. em->clear() forces a real read.
     */
    public function testFeedOrderPersistsToTheDatabaseNotJustTheEntityManager(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-in-tag-db@example.com');
        $tag = $this->makeTag($user, 'Tech', 0);
        $firstSubscription = $this->makeSub($user, 'https://db/1', 0, $tag, 0);
        $secondSubscription = $this->makeSub($user, 'https://db/2', 1, $tag, 1);
        $thirdSubscription = $this->makeSub($user, 'https://db/3', 2, $tag, 2);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/tags/' . $tag->getId() . '/feed-order', [
            'subscriptionIds' => [
                $thirdSubscription->getId(),
                $firstSubscription->getId(),
                $secondSubscription->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager()->clear();
        $joinPosition = function (int $subscriptionId) use ($tag): int {
            $subscription = $this->entityManager()->getRepository(Subscription::class)->find($subscriptionId);
            self::assertInstanceOf(Subscription::class, $subscription);
            foreach ($subscription->getSubscriptionTags() as $join) {
                if ($join->getTag()->requireId() === $tag->requireId()) {
                    return $join->getPosition();
                }
            }
            self::fail('subscription is not tagged');
        };
        self::assertSame(0, $joinPosition($thirdSubscription->requireId()));
        self::assertSame(1, $joinPosition($firstSubscription->requireId()));
        self::assertSame(2, $joinPosition($secondSubscription->requireId()));
    }

    public function testClearingTheLastTagAppendsTheFeedToTheUntaggedList(): void
    {
        $client = self::createClient();
        $user = $this->user('untag-append@example.com');
        $tag = $this->makeTag($user, 'Tech', 0);
        $this->makeSub($user, 'https://f/1', 0); // untagged, position 0
        $this->makeSub($user, 'https://f/2', 1); // untagged, position 1
        $tagged = $this->makeSub($user, 'https://f/3', 0, $tag, 0); // tagged, position 0
        $this->entityManager()->flush();

        // Remove its only tag: it joins the untagged list and must append (2),
        // not keep its stale position (0) and float to the top.
        $this->patch($client, $user, '/api/subscriptions/' . $tagged->getId(), [
            'customTitle' => null,
            'tagIds' => [],
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(Subscription::class, $tagged->requireId());
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertSame(2, $reloaded->getPosition());
    }

    public function testFeedOrderRejectsFeedNotInTag(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-foreign-feed@example.com');
        $tag = $this->makeTag($user, 'Tech', 0);
        $inTag = $this->makeSub($user, 'https://f/1', 0, $tag, 0);
        $notInTag = $this->makeSub($user, 'https://f/2', 1);
        $this->entityManager()->flush();

        // A feed that doesn't carry the tag makes the set inexact → 422.
        $this->patch($client, $user, '/api/tags/' . $tag->getId() . '/feed-order', [
            'subscriptionIds' => [$inTag->getId(), $notInTag->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
