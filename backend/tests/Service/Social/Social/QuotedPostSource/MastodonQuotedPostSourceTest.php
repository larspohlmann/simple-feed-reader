<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\QuotedPostSource;

use App\Service\LinkCard\Exception\QuotedPostUnavailableException;
use App\Service\Social\QuotedPostSource\MastodonQuotedPostSource;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonQuotedPostSourceTest extends TestCase
{
    use AssertsRefusal;

    private const string ADDRESS = 'https://mastodon.example/@alice/113';
    private const string STATUS = 'GET https://mastodon.example/api/v1/statuses/113';

    public function testAQuotedStatusIsReadFromItsOwnInstanceWithoutAToken(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer()]);

        $quote = $this->source($http)->quotedPostAt(self::ADDRESS);

        self::assertSame(['alice@mastodon.example', 'Quoted.'], [$quote->authorHandle, $quote->text]);
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[0]['headers']));
    }

    public function testAStatusNamedByItsUriAloneIsTheQuote(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer()]);

        $quote = $this->source($http)->quotedPostAt('https://mastodon.example/users/alice/statuses/113');

        self::assertSame(self::ADDRESS, $quote->url);
    }

    public function testAnAnswerAboutAnotherStatusIsRefused(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer([
            'uri' => 'https://mastodon.example/x/1',
            'url' => 'https://mastodon.example/@x/1',
        ])]);

        $this->assertRefused(
            fn () => $this->source($http)->quotedPostAt(self::ADDRESS),
            QuotedPostUnavailableException::class,
            'An instance that answers with another status gives no quote.',
        );
    }

    public function testAStatusWithoutAnAuthorGivesNoQuote(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(['account' => []])]);

        $this->assertRefused(
            fn () => $this->source($http)->quotedPostAt(self::ADDRESS),
            QuotedPostUnavailableException::class,
            'A status without an author has nothing to show.',
        );
    }

    public function testAnAddressOfAnotherShapeAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        $this->assertRefused(
            fn () => $this->source($http)->quotedPostAt('https://bsky.app/profile/a/post/b'),
            QuotedPostUnavailableException::class,
            'A Bluesky address is no Mastodon status.',
        );
        self::assertSame([], $http->requestLines());
    }

    /** @return iterable<string, array{int}> */
    public static function failedAnswers(): iterable
    {
        yield 'gone' => [Response::HTTP_NOT_FOUND];
        yield 'accounts only' => [Response::HTTP_UNAUTHORIZED];
        yield 'throttled' => [Response::HTTP_TOO_MANY_REQUESTS];
        yield 'broken' => [Response::HTTP_BAD_GATEWAY];
    }

    #[DataProvider('failedAnswers')]
    public function testAFailedAnswerGivesNoQuote(int $status): void
    {
        $http = new RoutedHttpClient([self::STATUS => new MockResponse('{}', ['http_code' => $status])]);

        $this->assertRefused(
            fn () => $this->source($http)->quotedPostAt(self::ADDRESS),
            QuotedPostUnavailableException::class,
            'A failed answer falls through to a link card.',
        );
    }

    private function source(RoutedHttpClient $http): MastodonQuotedPostSource
    {
        return new MastodonQuotedPostSource(SocialApiWiring::mastodonApiClient($http->client()));
    }

    /** @param array<string, mixed> $overrides */
    private static function answer(array $overrides = []): MockResponse
    {
        return new MockResponse(json_encode([
            'uri' => 'https://mastodon.example/users/alice/statuses/113',
            'url' => self::ADDRESS,
            'content' => '<p>Quoted.</p>',
            'account' => ['acct' => 'alice', 'url' => 'https://mastodon.example/@alice'],
            ...$overrides,
        ], \JSON_THROW_ON_ERROR));
    }
}
