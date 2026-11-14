<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\LemmyApiClient;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyApiClientTest extends TestCase
{
    private const string SITE = 'https://lemmy.example/api/v3/site';

    public function testASuccessfulAnswerIsReturned(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"ok":true}'));

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame('{"ok":true}', $answer->body);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function credentialErrors(): iterable
    {
        $session = 'The Lemmy instance refused this session.';

        yield 'incorrect login' => [
            Response::HTTP_BAD_REQUEST,
            'incorrect_login',
            'The Lemmy instance refused this username or password.',
        ];
        yield 'missing totp' => [
            Response::HTTP_BAD_REQUEST,
            'missing_totp_token',
            'This Lemmy account needs its two-factor code.',
        ];
        yield 'incorrect totp' => [
            Response::HTTP_BAD_REQUEST,
            'incorrect_totp_token',
            'The Lemmy instance refused this two-factor code.',
        ];
        yield 'email not verified' => [
            Response::HTTP_BAD_REQUEST,
            'email_not_verified',
            'Confirm your email address on the Lemmy instance first.',
        ];
        yield 'application pending' => [
            Response::HTTP_BAD_REQUEST,
            'registration_application_is_pending',
            'The Lemmy instance has not approved this account yet.',
        ];
        yield 'not logged in' => [
            Response::HTTP_BAD_REQUEST,
            'not_logged_in',
            $session,
        ];
        yield 'bare 401' => [Response::HTTP_UNAUTHORIZED, '', $session];
    }

    #[DataProvider('credentialErrors')]
    public function testCredentialErrorsAreRejectedCredentials(int $status, string $code, string $message): void
    {
        $client = $this->clientAnswering($this->errorAnswer($status, $code));

        try {
            $client->send(ApiRequestModel::get(self::SITE));
            self::fail('A credential error must not read as an answer.');
        } catch (SocialCredentialsRejectedException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        $banned = 'You are banned from this community.';

        yield 'downvotes' => ['downvotes_are_disabled', 'This Lemmy instance does not allow downvotes.'];
        yield 'locked' => ['locked', 'The post is locked.'];
        yield 'post locked' => ['post_is_locked', 'The post is locked.'];
        yield 'banned from community' => ['banned_from_community', $banned];
        yield 'person banned from community' => ['person_is_banned_from_community', $banned];
        yield 'person banned from site' => ['person_is_banned_from_site', $banned];
    }

    #[DataProvider('refusals')]
    public function testRefusalsAreRefusedPosts(string $code, string $message): void
    {
        $client = $this->clientAnswering($this->errorAnswer(Response::HTTP_BAD_REQUEST, $code));

        try {
            $client->send(ApiRequestModel::get(self::SITE));
            self::fail('A refusal must not read as an answer.');
        } catch (SocialPostRefusedException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function missingObjects(): iterable
    {
        yield 'object' => [Response::HTTP_BAD_REQUEST, 'couldnt_find_object'];
        yield 'post' => [Response::HTTP_BAD_REQUEST, 'couldnt_find_post'];
        yield 'deleted' => [Response::HTTP_BAD_REQUEST, 'deleted'];
        yield 'bare 404' => [Response::HTTP_NOT_FOUND, ''];
    }

    #[DataProvider('missingObjects')]
    public function testMissingObjectsAreNotFound(int $status, string $code): void
    {
        $client = $this->clientAnswering($this->errorAnswer($status, $code));

        $this->expectException(SocialPostNotFoundException::class);
        $this->expectExceptionMessage('The Lemmy instance cannot find this post.');

        $client->send(ApiRequestModel::get(self::SITE));
    }

    public function testATooManyRequestsAnswerIsThrottledWithItsDelay(): void
    {
        $client = $this->clientAnswering(new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '30'],
        ]));

        try {
            $client->send(ApiRequestModel::get(self::SITE));
            self::fail('A 429 must not read as an answer.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds);
        }
    }

    public function testARateLimitErrorIsThrottledWithoutADelay(): void
    {
        $client = $this->clientAnswering($this->errorAnswer(Response::HTTP_BAD_REQUEST, 'rate_limit_error'));

        try {
            $client->send(ApiRequestModel::get(self::SITE));
            self::fail('A rate limit error must not read as an answer.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertNull($exception->retryAfterSeconds);
        }
    }

    public function testAnyOtherAnswerIsUnreachable(): void
    {
        $client = $this->clientAnswering($this->errorAnswer(Response::HTTP_INTERNAL_SERVER_ERROR, ''));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Lemmy instance answered 500.');

        $client->send(ApiRequestModel::get(self::SITE));
    }

    public function testAnswerReturnsAnyStatusWithoutThrowing(): void
    {
        $client = $this->clientAnswering($this->errorAnswer(Response::HTTP_BAD_REQUEST, 'incorrect_login'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->answer(ApiRequestModel::get(self::SITE))->status);
    }

    private function errorAnswer(int $status, string $code): MockResponse
    {
        $body = '' === $code ? '' : json_encode(['error' => $code], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => $status]);
    }

    private function clientAnswering(MockResponse $response): LemmyApiClient
    {
        $http = new RoutedHttpClient(['GET ' . self::SITE => $response]);
        $guard = new UrlGuard(new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]), new IpValidator());

        return new LemmyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));
    }
}
