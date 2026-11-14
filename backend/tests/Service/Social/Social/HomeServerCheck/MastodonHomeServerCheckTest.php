<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HomeServerCheck;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HomeServerCheck\MastodonHomeServerCheck;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonHomeServerCheckTest extends TestCase
{
    private const string PROFILE_ADDRESS = 'https://mastodon.social/@Gargron/110';
    private const string USERS_ADDRESS = 'https://mastodon.social/users/Gargron/statuses/110';
    private const string STATUS = 'GET https://mastodon.social/api/v1/statuses/110';

    public function testAStatusItsOwnInstanceNoLongerHasIsConfirmedDeletedWithoutAToken(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(Response::HTTP_NOT_FOUND)]);

        self::assertTrue($this->check($http)->confirmsDeleted(self::PROFILE_ADDRESS));
        self::assertSame([self::STATUS], $http->requestLines());
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[0]['headers']));
    }

    public function testAUsersAddressAsksTheSameInstanceForTheSameStatus(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(Response::HTTP_NOT_FOUND)]);

        self::assertTrue($this->check($http)->confirmsDeleted(self::USERS_ADDRESS));
    }

    #[DataProvider('unprovenAnswers')]
    public function testAnyOtherAnswerProvesNothing(int $status): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer($status)]);

        self::assertFalse($this->check($http)->confirmsDeleted(self::PROFILE_ADDRESS));
    }

    /** @return iterable<string, array{int}> */
    public static function unprovenAnswers(): iterable
    {
        yield 'still there' => [Response::HTTP_OK];
        yield 'redirected' => [Response::HTTP_FOUND];
        yield 'closed to anonymous readers' => [Response::HTTP_UNAUTHORIZED];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
        yield 'gone is not a status answer' => [Response::HTTP_GONE];
        yield 'throttled' => [Response::HTTP_TOO_MANY_REQUESTS];
        yield 'failing' => [Response::HTTP_INTERNAL_SERVER_ERROR];
    }

    public function testAnAddressOfAnotherShapeProvesNothingAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        self::assertFalse($this->check($http)->confirmsDeleted('https://pixelfed.example/p/alice/123'));
        self::assertSame([], $http->requests);
    }

    public function testARemoteStatusSeenThroughAnotherInstanceProvesNothingAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        self::assertFalse($this->check($http)->confirmsDeleted('https://mastodon.social/@alice@other.social/123'));
        self::assertSame([], $http->requests);
    }

    public function testAnInstanceThatDoesNotAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::STATUS => new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->check($http)->confirmsDeleted(self::PROFILE_ADDRESS);
    }

    private function check(RoutedHttpClient $http): MastodonHomeServerCheck
    {
        return new MastodonHomeServerCheck(SocialApiWiring::mastodonApiClient($http->client()));
    }

    private static function answer(int $status): MockResponse
    {
        return new MockResponse('{}', [
            'http_code' => $status,
            'response_headers' => ['Location: https://elsewhere.example/api/v1/statuses/110'],
        ]);
    }
}
