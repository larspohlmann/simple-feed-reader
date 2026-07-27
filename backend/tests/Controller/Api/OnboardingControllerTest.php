<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Discovery\FeedDiscoveryInterface;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OnboardingControllerTest extends WebTestCase
{
    /** @return array<string, string> */
    private function authHeader(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @return list<CatalogFeed> */
    private function catalog(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $verge = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $ars = new CatalogFeed($category, 'Ars Technica', 'https://feeds.arstechnica.com/arstechnica/index');

        foreach ([$category, $verge, $ars] as $row) {
            $em->persist($row);
        }
        $em->flush();

        return [$verge, $ars];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/onboarding/subscribe', content: '{"catalogFeedIds":[1]}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSubscribesAndReportsTheTagsItCreated(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('onboard@example.com');
        [$verge, $ars] = $this->catalog();

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(
                ['catalogFeedIds' => [(int) $verge->getId(), (int) $ars->getId()]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['tagsCreated']);

        self::assertSame(2, $body['subscribed']);
        self::assertSame(0, $body['skipped']);
        self::assertSame(['Technology'], array_column($body['tagsCreated'], 'name'));
        self::assertIsArray($body['tagsCreated'][0]);
        self::assertSame('#3b82f6', $body['tagsCreated'][0]['color']);
    }

    /**
     * Through the real HTTP kernel, not a direct service call: a direct
     * invocation here could assert something the wired-up app never does.
     */
    public function testSubscribingIssuesNoDiscoveryRequests(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('nodiscovery@example.com');
        [$verge] = $this->catalog();

        $discovery = $this->createMock(FeedDiscoveryInterface::class);
        $discovery->expects(self::never())->method('discover');
        self::getContainer()->set(FeedDiscoveryInterface::class, $discovery);

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(['catalogFeedIds' => [(int) $verge->getId()]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    public function testAnEmptySelectionIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('empty@example.com');

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(['catalogFeedIds' => []], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testResubmittingTheSameSelectionIsANoOpNotAnError(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('again@example.com');
        [$verge] = $this->catalog();
        $payload = json_encode(['catalogFeedIds' => [(int) $verge->getId()]], \JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/onboarding/subscribe', server: $headers, content: $payload);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/onboarding/subscribe', server: $headers, content: $payload);
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(0, $body['subscribed']);
        self::assertSame(1, $body['skipped']);
        self::assertSame([], $body['tagsCreated']);
    }

    public function testASelectionCrossingTheCapStopsCleanlyAndReportsWhatItCreated(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('cap@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'cap@example.com']);
        self::assertNotNull($user);

        // One short of the cap, so a two-feed selection lands one and skips one.
        for ($i = 0; $i < SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER - 1; ++$i) {
            $feed = new Feed(\sprintf('https://filler%d.example.com/rss.xml', $i));
            $em->persist($feed);
            $em->persist(new Subscription($user, $feed, $clock->now()));
        }
        $em->flush();

        [$verge, $ars] = $this->catalog();

        $client->request(
            'POST',
            '/api/onboarding/subscribe',
            server: $headers,
            content: json_encode(
                ['catalogFeedIds' => [(int) $verge->getId(), (int) $ars->getId()]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        self::assertSame(1, $body['subscribed']);
        self::assertSame(1, $body['skippedOverLimit']);
    }
}
