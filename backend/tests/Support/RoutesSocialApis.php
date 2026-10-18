<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Crypto\SocialCredentialCipher;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;

/** For an ApiTestCase that answers the app's outbound social API calls itself. */
trait RoutesSocialApis
{
    private const string MASTODON_POST_URL = 'https://mastodon.social/@Gargron/110';

    private RoutedHttpClient $http;

    /**
     * @param list<string>                $hosts  each resolves to one public address
     * @param array<string, MockResponse> $routes
     */
    private function clientRouting(array $hosts, array $routes): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would discard the routed client.
        $client->disableReboot();
        $this->http = new RoutedHttpClient($routes);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $this->http->client());
        $container->set(
            NativeDnsResolver::class,
            new StaticDnsResolver(array_fill_keys($hosts, ['93.184.216.34'])),
        );

        return $client;
    }

    private function mastodonPostFor(User $user): int
    {
        return $this->mastodonPostOf($user)->requireId();
    }

    private function mastodonPostOf(User $user): Entry
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());

        return $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE),
            self::MASTODON_POST_URL,
            '2026-10-12T10:00:00Z',
        );
    }

    private function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokens->create($user));

        return $user;
    }

    private function mastodonAccountOf(User $user): SocialAccount
    {
        /** @var InstanceSecretCipher $instanceCipher */
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        $account = new SocialAccount(
            $user,
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
            (new SocialCredentialCipher($instanceCipher))->seal($user->requireId(), SocialAccounts::ACCESS_TOKEN),
            '7890',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    /** @return list<string> */
    private function routesAsked(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->http->requests,
        );
    }
}
