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

final class MoveFeedToTagTest extends WebTestCase
{
    public function testMovePlacesTheFeedAtTheDropIndexInTheTargetTag(): void
    {
        $client = self::createClient();
        $user = $this->user('mover@example.com');
        $news = $this->makeTag($user, 'News', 0);
        $tech = $this->makeTag($user, 'Tech', 1);
        $x = $this->makeSub($user, 'https://x.example.com/rss', 0, $tech, 0);
        $y = $this->makeSub($user, 'https://y.example.com/rss', 0, $tech, 1);
        $moved = $this->makeSub($user, 'https://m.example.com/rss', 0, $news, 0);
        $this->em()->flush();

        $this->patch($client, $user, '/api/subscriptions/' . $moved->getId() . '/move-to-tag', [
            'fromTagId' => $news->getId(),
            'toTagId' => $tech->getId(),
            'position' => 1,
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $positions = $this->tagFeedPositions($client, $user, (int) $tech->getId());
        self::assertSame(0, $positions[(int) $x->getId()]);
        self::assertSame(1, $positions[(int) $moved->getId()]);
        self::assertSame(2, $positions[(int) $y->getId()]);
        $newsFeeds = $this->tagFeedPositions($client, $user, (int) $news->getId());
        self::assertArrayNotHasKey((int) $moved->getId(), $newsFeeds);
    }

    public function testAForeignTargetTagIsRejectedAndNothingChanges(): void
    {
        $client = self::createClient();
        $user = $this->user('rejectee@example.com');
        $stranger = $this->user('stranger@example.com');
        $news = $this->makeTag($user, 'News', 0);
        $theirs = $this->makeTag($stranger, 'Theirs', 0);
        $moved = $this->makeSub($user, 'https://m.example.com/rss', 0, $news, 0);
        $this->em()->flush();

        $this->patch($client, $user, '/api/subscriptions/' . $moved->getId() . '/move-to-tag', [
            'fromTagId' => $news->getId(),
            'toTagId' => $theirs->getId(),
            'position' => 0,
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->em()->clear();
        $positions = $this->tagFeedPositions($client, $user, (int) $news->getId());
        self::assertArrayHasKey((int) $moved->getId(), $positions);
    }

    public function testAnUnknownSubscriptionAnswers404(): void
    {
        $client = self::createClient();
        $user = $this->user('missing@example.com');
        $tech = $this->makeTag($user, 'Tech', 0);
        $this->em()->flush();

        $this->patch($client, $user, '/api/subscriptions/999999/move-to-tag', [
            'toTagId' => $tech->getId(),
            'position' => 0,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

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

    /**
     * The per-tag feed positions for one tag, read back through the API.
     *
     * @return array<int, int> subscriptionId => position within the tag
     */
    private function tagFeedPositions(KernelBrowser $client, User $user, int $tagId): array
    {
        $client->request('GET', '/api/subscriptions', server: $this->headers($user));
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['subscriptions']);

        $out = [];
        foreach ($data['subscriptions'] as $subscription) {
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
