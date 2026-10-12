<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryNetworkScopeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testANetworkListsItsPrivateAndProfileFeedsAndNothingElse(): void
    {
        [$headers, $user] = $this->auth('network-list@example.com');
        $this->seedNetworks($user);

        $this->client->request('GET', '/api/entries?network=bluesky', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['profile-post', 'home-post'], $this->guidsOfResponse());
    }

    public function testANetworkNeverListsAnotherUsersFeeds(): void
    {
        [$headers, $user] = $this->auth('network-own@example.com');
        $this->seedNetworks($user);
        [, $stranger] = $this->auth('network-stranger@example.com');
        $foreign = $this->fixtures()->subscribe($stranger, 'https://bsky.app/profile/bob.bsky.social/rss');
        $this->fixtures()->post($foreign, 'foreign-post', '2026-10-09T00:00:00Z');

        $this->client->request('GET', '/api/entries?network=bluesky', server: $headers);

        self::assertSame(['profile-post', 'home-post'], $this->guidsOfResponse());
    }

    public function testAnUnknownNetworkIsAValidationError(): void
    {
        [$headers] = $this->auth('network-unknown@example.com');

        $this->client->request('GET', '/api/entries?network=myspace', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->body();
        self::assertSame('validation_error', $body['type']);
        self::assertSame(['network' => ['Unknown network. Use one of: bluesky, mastodon.']], $body['errors']);
    }

    public function testMarkingANetworkReadLeavesItsUnreadListEmptyAndOtherFeedsUnread(): void
    {
        [$headers, $user] = $this->auth('network-mark@example.com');
        $this->seedNetworks($user);

        $this->client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['scope' => 'network', 'network' => 'bluesky', 'until' => '2026-10-12T00:00:00Z'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/entries?network=bluesky&view=unread', server: $headers);
        self::assertSame([], $this->guidsOfResponse());
        $this->client->request('GET', '/api/entries?network=mastodon&view=unread', server: $headers);
        self::assertSame(['mastodon-post'], $this->guidsOfResponse());
    }

    public function testMarkingANetworkReadWithoutANetworkIsAValidationError(): void
    {
        [$headers] = $this->auth('network-mark-missing@example.com');

        $this->client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'network', 'until' => '2026-10-12T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            ['network' => ['A network is required when scope is "network".']],
            $this->body()['errors'],
        );
    }

    private function seedNetworks(User $user): void
    {
        $fixtures = $this->fixtures();
        $home = $fixtures->subscribe($user, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $home->setIncludeInAllItems(false);
        $fixtures->post($home, 'home-post', '2026-10-05T00:00:00Z');
        $profile = $fixtures->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $fixtures->post($profile, 'profile-post', '2026-10-06T00:00:00Z');
        $mastodon = $fixtures->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $fixtures->post($mastodon, 'mastodon-post', '2026-10-07T00:00:00Z');
        $plain = $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN);
        $fixtures->post($plain, 'plain-post', '2026-10-08T00:00:00Z');
    }

    /** @return list<string> */
    private function guidsOfResponse(): array
    {
        $entries = $this->body()['entries'];
        self::assertIsArray($entries);

        return array_map(static function (mixed $entry): string {
            self::assertIsArray($entry);
            self::assertIsString($entry['title']);

            return $entry['title'];
        }, array_values($entries));
    }

    /** @return array<array-key, mixed> */
    private function body(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($this->entityManager(), $hasher))->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function fixtures(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
