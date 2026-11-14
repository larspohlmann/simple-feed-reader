<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostCountsSource;

use App\Service\Social\Exception\PostNotPubliclyCountableException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\PostCountsSource\MastodonOriginCountsSource;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonOriginCountsSourceTest extends TestCase
{
    use AssertsRefusal;

    private const string PROFILE_ADDRESS = 'https://mastodon.example/@Gargron/110';
    private const string USERS_ADDRESS = 'https://mastodon.example/users/Gargron/statuses/110';
    private const string STATUS = 'GET https://mastodon.example/api/v1/statuses/110';

    public function testAStatusCountsComeFromItsOriginWithoutAToken(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::statusAnswer()]);

        $counts = $this->source($http)->countsOf(self::PROFILE_ADDRESS);

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $counts);
        self::assertSame([self::STATUS], $http->requestLines());
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[0]['headers']));
    }

    public function testAUsersAddressAsksForTheSameStatus(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::statusAnswer()]);

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $this->source($http)->countsOf(self::USERS_ADDRESS));
    }

    public function testAStatusThatNamesTheAddressOnlyByItsUrlIsThePost(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::statusAnswer(['uri' => 'https://mastodon.example/x/1'])]);

        self::assertSame(5, $this->source($http)->countsOf(self::PROFILE_ADDRESS)->likes);
    }

    public function testAStatusThatNamesTheAddressOnlyByItsUriIsThePost(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::statusAnswer(['url' => 'https://mastodon.example/x/1'])]);

        self::assertSame(5, $this->source($http)->countsOf(self::USERS_ADDRESS)->likes);
    }

    public function testAnAddressOfAnotherShapeDoesNotApplyAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        $this->assertRefused(
            fn (): SocialCountsModel => $this->source($http)->countsOf('https://pixelfed.example/p/alice/123'),
            PostNotPubliclyCountableException::class,
            'A Pixelfed address names no Mastodon status.',
        );

        self::assertSame([], $http->requests);
    }

    public function testAStatusThatIsAnotherPostDoesNotApply(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::statusAnswer([
            'uri' => 'https://mastodon.example/users/other/statuses/110',
            'url' => 'https://mastodon.example/@other/110',
        ])]);

        $this->expectException(PostNotPubliclyCountableException::class);

        $this->source($http)->countsOf(self::PROFILE_ADDRESS);
    }

    public function testAStatusGoneFromItsOriginIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(Response::HTTP_NOT_FOUND)]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->source($http)->countsOf(self::PROFILE_ADDRESS);
    }

    #[DataProvider('closedToThePublic')]
    public function testAnOriginClosedToAnonymousReadersDoesNotApply(int $status): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer($status)]);

        $this->expectException(PostNotPubliclyCountableException::class);

        $this->source($http)->countsOf(self::PROFILE_ADDRESS);
    }

    /** @return iterable<string, array{int}> */
    public static function closedToThePublic(): iterable
    {
        yield 'unauthorized' => [Response::HTTP_UNAUTHORIZED];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
    }

    public function testAThrottlingOriginIsAThrottledNetworkWithItsWait(): void
    {
        $http = new RoutedHttpClient([self::STATUS => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After: 30'],
        ])]);

        try {
            $this->source($http)->countsOf(self::PROFILE_ADDRESS);
            self::fail('A throttling origin must reach the caller as a throttled network.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds);
        }
    }

    public function testAFailingOriginIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(Response::HTTP_INTERNAL_SERVER_ERROR)]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->countsOf(self::PROFILE_ADDRESS);
    }

    public function testAStatusWithoutItsCountsIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::statusAnswer(['favourites_count' => null])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->countsOf(self::PROFILE_ADDRESS);
    }

    private function source(RoutedHttpClient $http): MastodonOriginCountsSource
    {
        return new MastodonOriginCountsSource(SocialApiWiring::mastodonApiClient($http->client()));
    }

    /** @param array<string, string|int|null> $overrides */
    private static function statusAnswer(array $overrides = []): MockResponse
    {
        return new MockResponse(json_encode([
            'id' => '110',
            'uri' => self::USERS_ADDRESS,
            'url' => self::PROFILE_ADDRESS,
            'favourites_count' => 5,
            'reblogs_count' => 2,
            'replies_count' => 1,
            ...$overrides,
        ], \JSON_THROW_ON_ERROR));
    }

    private static function answer(int $status): MockResponse
    {
        return new MockResponse('{}', ['http_code' => $status]);
    }
}
