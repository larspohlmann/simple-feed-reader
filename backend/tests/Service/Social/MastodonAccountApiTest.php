<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\MastodonAccountApi;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonAccountApiTest extends TestCase
{
    private const string STATUS = 'https://mastodon.example/api/v1/statuses/110';
    private const string STATUS_PATH = '/api/v1/statuses/110';
    private const string OUTSIDE_SCOPES = '{"error":"This action is outside the authorized scopes"}';

    public function testAnAuthorizedGetCarriesTheAccountsTokenToItsOwnInstance(): void
    {
        $request = SocialApiWiring::mastodonAccountApi((new RoutedHttpClient([]))->client())
            ->authorizedGet(SocialAccounts::mastodon(), self::STATUS_PATH);

        self::assertSame(self::STATUS, $request->url);
        self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
    }

    public function testAQuestionAboutAPostCarriesTheAccountsToken(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::STATUS => new MockResponse('{"id":"110"}')]);

        $answer = SocialApiWiring::mastodonAccountApi($http->client())
            ->askAboutPost(SocialAccounts::mastodon(), self::STATUS_PATH);

        self::assertSame('{"id":"110"}', $answer->body);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAnActionPostsTheTokenAndNoBody(): void
    {
        $http = new RoutedHttpClient(['POST ' . self::STATUS . '/favourite' => new MockResponse('{}')]);

        SocialApiWiring::mastodonAccountApi($http->client())
            ->actOnPost(SocialAccounts::mastodon(), self::STATUS_PATH . '/favourite', 'write:favourites');

        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
        self::assertSame('', $http->requests[0]['body']);
    }

    /**
     * @param \Closure(MastodonAccountApi): mixed $call
     * @param class-string<\Throwable>            $expected
     */
    #[DataProvider('postAnswers')]
    public function testAPostCallMapsEachStatusToItsException(
        string $route,
        MockResponse $answer,
        \Closure $call,
        string $expected,
    ): void {
        $http = new RoutedHttpClient([$route => $answer]);

        $this->expectException($expected);

        $call(SocialApiWiring::mastodonAccountApi($http->client()));
    }

    /** @return iterable<string, array{string, MockResponse, \Closure(MastodonAccountApi): mixed, class-string<\Throwable>}> */
    public static function postAnswers(): iterable
    {
        $ask = static fn (MastodonAccountApi $api): mixed => $api->askAboutPost(
            SocialAccounts::mastodon(),
            self::STATUS_PATH,
        );
        $act = static fn (MastodonAccountApi $api): mixed => $api->actOnPost(
            SocialAccounts::mastodon(),
            self::STATUS_PATH,
            'write:favourites',
        );
        foreach (['GET' => $ask, 'POST' => $act] as $method => $call) {
            $route = $method . ' ' . self::STATUS;
            yield $method . ' unauthorised' => [
                $route,
                self::answer('{}', Response::HTTP_UNAUTHORIZED),
                $call,
                SocialCredentialsRejectedException::class,
            ];
            yield $method . ' outside scopes' => [
                $route,
                self::answer(self::OUTSIDE_SCOPES, Response::HTTP_FORBIDDEN),
                $call,
                SocialTokenScopeMissingException::class,
            ];
            yield $method . ' forbidden' => [
                $route,
                self::answer('{}', Response::HTTP_FORBIDDEN),
                $call,
                SocialPostRefusedException::class,
            ];
            yield $method . ' gone' => [
                $route,
                self::answer('{}', Response::HTTP_NOT_FOUND),
                $call,
                SocialPostNotFoundException::class,
            ];
            yield $method . ' failed' => [
                $route,
                self::answer('{}', Response::HTTP_INTERNAL_SERVER_ERROR),
                $call,
                SocialNetworkUnreachableException::class,
            ];
        }
    }

    public function testAMissingScopeNamesTheScopeTheCallNeeded(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::STATUS => self::answer(self::OUTSIDE_SCOPES, Response::HTTP_FORBIDDEN),
            'POST ' . self::STATUS => self::answer(self::OUTSIDE_SCOPES, Response::HTTP_FORBIDDEN),
        ]);
        $api = SocialApiWiring::mastodonAccountApi($http->client());

        self::assertSame('read', self::missingScope(
            static fn (): mixed => $api->askAboutPost(SocialAccounts::mastodon(), self::STATUS_PATH),
        ));
        self::assertSame('write:favourites', self::missingScope(
            static fn (): mixed => $api->actOnPost(SocialAccounts::mastodon(), self::STATUS_PATH, 'write:favourites'),
        ));
    }

    private static function answer(string $body, int $status): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status]);
    }

    /** @param \Closure(): mixed $call */
    private static function missingScope(\Closure $call): string
    {
        try {
            $call();
        } catch (SocialTokenScopeMissingException $exception) {
            self::assertSame(
                'The Mastodon access token of this account lacks the permission for this action.',
                $exception->getMessage(),
            );

            return $exception->missingScope;
        }
        self::fail('The call must name the scope it lacked.');
    }
}
