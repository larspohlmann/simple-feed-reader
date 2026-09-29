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

final class MoveFeedToTagTest extends WebTestCase
{
    use SeedsUsers;

    public function testMovePlacesTheFeedAtTheDropIndexInTheTargetTag(): void
    {
        $client = self::createClient();
        $user = $this->user('mover@example.com');
        $news = $this->makeTag($user, 'News', 0);
        $tech = $this->makeTag($user, 'Tech', 1);
        $ahead = $this->makeSubscription($user, 'https://x.example.com/rss', 0, $tech, 0);
        $behind = $this->makeSubscription($user, 'https://y.example.com/rss', 0, $tech, 1);
        $moved = $this->makeSubscription($user, 'https://m.example.com/rss', 0, $news, 0);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/subscriptions/' . $moved->getId() . '/move-to-tag', [
            'fromTagId' => $news->getId(),
            'toTagId' => $tech->getId(),
            'position' => 1,
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $positions = $this->tagFeedPositions($client, $user, $tech->requireId());
        self::assertSame(0, $positions[$ahead->requireId()]);
        self::assertSame(1, $positions[$moved->requireId()]);
        self::assertSame(2, $positions[$behind->requireId()]);
        $newsFeeds = $this->tagFeedPositions($client, $user, $news->requireId());
        self::assertArrayNotHasKey($moved->requireId(), $newsFeeds);
    }

    public function testMoveAnswersWithTheMovedSubscription(): void
    {
        $client = self::createClient();
        $user = $this->user('mover-body@example.com');
        $news = $this->makeTag($user, 'News', 0);
        $tech = $this->makeTag($user, 'Tech', 1);
        $moved = $this->makeSubscription($user, 'https://m.example.com/rss', 0, $news, 0);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/subscriptions/' . $moved->getId() . '/move-to-tag', [
            'fromTagId' => $news->getId(),
            'toTagId' => $tech->getId(),
            'position' => 0,
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscription']);
        self::assertSame($moved->getId(), $body['subscription']['id']);
        self::assertSame('https://m.example.com/rss', $body['subscription']['feedUrl']);
    }

    public function testAForeignTargetTagIsRejectedAndNothingChanges(): void
    {
        $client = self::createClient();
        $user = $this->user('rejectee@example.com');
        $stranger = $this->user('stranger@example.com');
        $news = $this->makeTag($user, 'News', 0);
        $theirs = $this->makeTag($stranger, 'Theirs', 0);
        $moved = $this->makeSubscription($user, 'https://m.example.com/rss', 0, $news, 0);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/subscriptions/' . $moved->getId() . '/move-to-tag', [
            'fromTagId' => $news->getId(),
            'toTagId' => $theirs->getId(),
            'position' => 0,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->entityManager()->clear();
        $positions = $this->tagFeedPositions($client, $user, $news->requireId());
        self::assertArrayHasKey($moved->requireId(), $positions);
    }

    public function testAnUnknownSubscriptionAnswers404(): void
    {
        $client = self::createClient();
        $user = $this->user('missing@example.com');
        $tech = $this->makeTag($user, 'Tech', 0);
        $this->entityManager()->flush();

        $this->patch($client, $user, '/api/subscriptions/999999/move-to-tag', [
            'toTagId' => $tech->getId(),
            'position' => 0,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

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

    private function makeSubscription(
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

    /**
     * The per-tag feed positions for one tag, read back through the API.
     *
     * @return array<int, int> subscriptionId => position within the tag
     */
    private function tagFeedPositions(KernelBrowser $client, User $user, int $tagId): array
    {
        $client->request('GET', '/api/subscriptions', server: $this->headers($user));
        $responseBody = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($responseBody);
        self::assertIsArray($responseBody['subscriptions']);

        $out = [];
        foreach ($responseBody['subscriptions'] as $subscription) {
            self::assertIsArray($subscription);
            self::assertIsArray($subscription['tags']);
            foreach ($subscription['tags'] as $tag) {
                self::assertIsArray($tag);
                if ($tag['id'] === $tagId) {
                    self::assertIsInt($subscription['id']);
                    self::assertIsInt($tag['position']);
                    $out[$subscription['id']] = $tag['position'];
                }
            }
        }

        return $out;
    }
}
