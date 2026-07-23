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

final class ReorderTest extends WebTestCase
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

    private function makeTag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->em()->persist($tag);

        return $tag;
    }

    private function makeSub(User $user, string $url, int $position, ?Tag $tag = null, int $tagPos = 0): Subscription
    {
        $feed = new Feed($url);
        $this->em()->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $sub->setPosition($position);
        if (null !== $tag) {
            $sub->addTag($tag, $tagPos);
        }
        $this->em()->persist($sub);

        return $sub;
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
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['tags']);
        $out = [];
        foreach ($data['tags'] as $tag) {
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
        $a = $this->makeTag($user, 'Alpha', 0);
        $b = $this->makeTag($user, 'Beta', 1);
        $c = $this->makeTag($user, 'Gamma', 2);
        $this->em()->flush();

        // New order: Gamma, Alpha, Beta.
        $this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$c->getId(), $a->getId(), $b->getId()]]);
        self::assertResponseIsSuccessful();

        $positions = $this->tagPositions($client, $user);
        self::assertSame(0, $positions[(int) $c->getId()]);
        self::assertSame(1, $positions[(int) $a->getId()]);
        self::assertSame(2, $positions[(int) $b->getId()]);
    }

    public function testReorderTagsRejectsIncompleteSet(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-partial@example.com');
        $a = $this->makeTag($user, 'Alpha', 0);
        $this->makeTag($user, 'Beta', 1);
        $this->em()->flush();

        // Missing Beta → ambiguous → 422.
        $this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$a->getId()]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReorderTagsCannotTouchAnotherUsersTag(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-owner@example.com');
        $stranger = $this->user('reorder-stranger@example.com');
        $mine = $this->makeTag($user, 'Mine', 0);
        $theirs = $this->makeTag($stranger, 'Theirs', 0);
        $this->em()->flush();

        // A foreign id is not in the owner's set → 422, no cross-tenant write.
        $this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$mine->getId(), $theirs->getId()]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReorderUntaggedSubscriptions(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-feeds@example.com');
        $s1 = $this->makeSub($user, 'https://f/1', 0);
        $s2 = $this->makeSub($user, 'https://f/2', 1);
        $s3 = $this->makeSub($user, 'https://f/3', 2);
        $this->em()->flush();

        $this->patch($client, $user, '/api/subscriptions/reorder', [
            'subscriptionIds' => [$s3->getId(), $s1->getId(), $s2->getId()],
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->em()->clear();
        $reload = function (int $id): Subscription {
            $sub = $this->em()->find(Subscription::class, $id);
            self::assertInstanceOf(Subscription::class, $sub);

            return $sub;
        };
        self::assertSame(0, $reload((int) $s3->getId())->getPosition());
        self::assertSame(1, $reload((int) $s1->getId())->getPosition());
        self::assertSame(2, $reload((int) $s2->getId())->getPosition());
    }

    public function testFeedOrderWithinTagPersistsPerTagPosition(): void
    {
        $client = self::createClient();
        $user = $this->user('reorder-in-tag@example.com');
        $tag = $this->makeTag($user, 'Tech', 0);
        $s1 = $this->makeSub($user, 'https://f/1', 0, $tag, 0);
        $s2 = $this->makeSub($user, 'https://f/2', 1, $tag, 1);
        $s3 = $this->makeSub($user, 'https://f/3', 2, $tag, 2);
        $this->em()->flush();

        // New within-tag order: s3, s1, s2.
        $this->patch($client, $user, '/api/tags/' . $tag->getId() . '/feed-order', [
            'subscriptionIds' => [$s3->getId(), $s1->getId(), $s2->getId()],
        ]);
        self::assertResponseStatusCodeSame(204);

        // The embedded tag position on each subscription is the per-tag order.
        $client->request('GET', '/api/subscriptions', server: $this->headers($user));
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['subscriptions']);
        $perTag = [];
        foreach ($data['subscriptions'] as $sub) {
            self::assertIsArray($sub);
            self::assertIsArray($sub['tags']);
            self::assertIsArray($sub['tags'][0]);
            self::assertIsInt($sub['id']);
            self::assertIsInt($sub['tags'][0]['position']);
            $perTag[$sub['id']] = $sub['tags'][0]['position'];
        }
        self::assertSame(0, $perTag[(int) $s3->getId()]);
        self::assertSame(1, $perTag[(int) $s1->getId()]);
        self::assertSame(2, $perTag[(int) $s2->getId()]);
    }

    public function testClearingTheLastTagAppendsTheFeedToTheUntaggedList(): void
    {
        $client = self::createClient();
        $user = $this->user('untag-append@example.com');
        $tag = $this->makeTag($user, 'Tech', 0);
        $this->makeSub($user, 'https://f/1', 0); // untagged, position 0
        $this->makeSub($user, 'https://f/2', 1); // untagged, position 1
        $tagged = $this->makeSub($user, 'https://f/3', 0, $tag, 0); // tagged, position 0
        $this->em()->flush();

        // Remove its only tag: it joins the untagged list and must append (2),
        // not keep its stale position (0) and float to the top.
        $this->patch($client, $user, '/api/subscriptions/' . $tagged->getId(), [
            'customTitle' => null,
            'tagIds' => [],
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $reloaded = $this->em()->find(Subscription::class, (int) $tagged->getId());
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
        $this->em()->flush();

        // A feed that doesn't carry the tag makes the set inexact → 422.
        $this->patch($client, $user, '/api/tags/' . $tag->getId() . '/feed-order', [
            'subscriptionIds' => [$inTag->getId(), $notInTag->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
