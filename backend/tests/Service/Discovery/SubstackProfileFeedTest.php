<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\SubstackProfileFeed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubstackProfileFeedTest extends TestCase
{
    /**
     * The whole point of the API lookup: the handle need not equal the
     * publication subdomain. `@abbeyheffer` publishes at `theopenbookshelf`, and
     * the string rewrite this replaced could never have found it.
     */
    public function testResolvesAProfileToItsPrimaryPublicationSubdomain(): void
    {
        $fetcher = $this->fetcherResolving('abbeyheffer', 'theopenbookshelf');

        self::assertSame(
            'https://theopenbookshelf.substack.com/feed',
            (new SubstackProfileFeed($fetcher))->feedUrl('https://substack.com/@abbeyheffer'),
        );
    }

    /** The API is asked about the exact handle, lowercased, and nothing else. */
    public function testQueriesThePublicProfileApiForThatHandle(): void
    {
        $fetcher = $this->fetcherResolving('rushkoff', 'rushkoff');

        (new SubstackProfileFeed($fetcher))->feedUrl('https://substack.com/@rushkoff');

        self::assertSame(
            ['https://substack.com/api/v1/user/rushkoff/public_profile'],
            $fetcher->fetchedUrls,
        );
    }

    #[DataProvider('profileUrls')]
    public function testResolvesEveryShapeOfProfileShareUrl(string $enteredUrl, string $handle): void
    {
        $fetcher = $this->fetcherResolving($handle, $handle);

        self::assertSame(
            sprintf('https://%s.substack.com/feed', $handle),
            (new SubstackProfileFeed($fetcher))->feedUrl($enteredUrl),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function profileUrls(): iterable
    {
        yield 'the share URL with its tracking query' => [
            'https://substack.com/@rushkoff?r=260csv&utm_medium=ios&utm_source=stories',
            'rushkoff',
        ];
        yield 'a bare profile URL' => ['https://substack.com/@rushkoff', 'rushkoff'];
        yield 'a trailing slash' => ['https://substack.com/@rushkoff/', 'rushkoff'];
        yield 'the www host' => ['https://www.substack.com/@rushkoff', 'rushkoff'];
        yield 'a mixed-case host and handle canonicalised to lowercase' => [
            'https://Substack.com/@Rushkoff',
            'rushkoff',
        ];
        yield 'a handle with a hyphen and underscore' => [
            'https://substack.com/@the_daily-widget',
            'the_daily-widget',
        ];
    }

    #[DataProvider('nonProfileUrls')]
    public function testLeavesEverythingElseAloneWithoutAskingTheApi(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull((new SubstackProfileFeed($fetcher))->feedUrl($enteredUrl));
        // A URL that is not a profile share must short-circuit before any fetch.
        self::assertSame([], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string}> */
    public static function nonProfileUrls(): iterable
    {
        yield 'a publication URL already resolves via probing' => ['https://rushkoff.substack.com'];
        yield 'a publication feed URL' => ['https://rushkoff.substack.com/feed'];
        yield 'a Substack post URL' => ['https://substack.com/home/post/p-123'];
        yield 'a deeper profile path such as a note' => ['https://substack.com/@rushkoff/note/c-1'];
        yield 'an @handle that is not at the path root' => ['https://substack.com/section/@rushkoff'];
        yield 'a profile with no handle' => ['https://substack.com/@'];
        yield 'a look-alike host' => ['https://substack.com.evil.example/@rushkoff'];
        yield 'a non-Substack host' => ['https://example.com/@rushkoff'];
        yield 'a substack subdomain profile path is not the share form' => [
            'https://rushkoff.substack.com/@rushkoff',
        ];
    }

    #[DataProvider('unresolvableProfiles')]
    public function testFallsThroughWhenTheApiDoesNotYieldAPublicationSubdomain(string $body): void
    {
        $fetcher = $this->fetcherReturningBody('rushkoff', $body);

        self::assertNull((new SubstackProfileFeed($fetcher))->feedUrl('https://substack.com/@rushkoff'));
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvableProfiles(): iterable
    {
        yield 'the profile has no primary publication' => ['{"handle":"rushkoff"}'];
        yield 'the primary publication is null' => ['{"primaryPublication":null}'];
        yield 'the primary publication carries no subdomain' => ['{"primaryPublication":{"name":"x"}}'];
        yield 'the subdomain is not a string' => ['{"primaryPublication":{"subdomain":42}}'];
        yield 'the subdomain is empty' => ['{"primaryPublication":{"subdomain":""}}'];
        yield 'the body is not JSON at all' => [
            /** @lang TEXT */ '<!doctype html><html><body>Profile</body></html>',
        ];
        yield 'the body is a bare JSON scalar' => ['"rushkoff"'];
    }

    /**
     * A subdomain carrying a dot or a slash could smuggle a second host or a
     * path into the feed URL. It builds a hostname, so it must be a bare label —
     * even though Substack is the source.
     */
    #[DataProvider('unsafeSubdomains')]
    public function testRejectsASubdomainThatIsNotABareLabel(string $subdomain): void
    {
        $fetcher = $this->fetcherResolving('rushkoff', $subdomain);

        self::assertNull((new SubstackProfileFeed($fetcher))->feedUrl('https://substack.com/@rushkoff'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSubdomains(): iterable
    {
        yield 'a dotted second host' => ['evil.example.com'];
        yield 'a path traversal' => ['rushkoff/../evil'];
        yield 'a host and path' => ['evil.com/feed'];
        yield 'a leading dot' => ['.rushkoff'];
    }

    /** An unreachable or refusing API degrades to "not resolved", never an error. */
    public function testFallsThroughWhenTheApiCannotBeReached(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(
            'https://substack.com/api/v1/user/rushkoff/public_profile',
            new FeedUnreachableException('x: HTTP 404', 404),
        );

        self::assertNull((new SubstackProfileFeed($fetcher))->feedUrl('https://substack.com/@rushkoff'));
    }

    private function fetcherResolving(string $handle, string $subdomain): StubFeedFetcher
    {
        return $this->fetcherReturningBody(
            $handle,
            (string) json_encode(['primaryPublication' => ['subdomain' => $subdomain]], JSON_THROW_ON_ERROR),
        );
    }

    private function fetcherReturningBody(string $handle, string $body): StubFeedFetcher
    {
        $apiUrl = sprintf('https://substack.com/api/v1/user/%s/public_profile', $handle);
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn($apiUrl, FetchResponse::fetched(
            $apiUrl,
            permanentRedirect: false,
            body: $body,
            etag: null,
            lastModified: null,
        ));

        return $fetcher;
    }
}
