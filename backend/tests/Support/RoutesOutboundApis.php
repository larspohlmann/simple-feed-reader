<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;

/** For an ApiTestCase that answers the app's outbound API calls itself and signs a user in. */
trait RoutesOutboundApis
{
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

    private function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokens->create($user));

        return $user;
    }
}
