<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\FaviconResolver;

use App\Service\Outbound\Fetch\Exception\FeedUnreachableException;
use App\Service\Outbound\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Outbound\Fetch\Model\FetchResponseModel;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FaviconResolverTest extends TestCase
{
    private function resolver(StubFeedFetcher $fetcher): FaviconResolver
    {
        return new FaviconResolver($fetcher, new NullLogger());
    }

    private function page(
        string $head,
        string $finalUrl = 'https://blog.example.com/',
        string $body = 'x',
    ): FetchResponseModel {
        return FetchResponseModel::fetched(
            $finalUrl,
            permanentRedirect: false,
            body: /** @lang TEXT */ '<!doctype html><html><head>' . $head . '</head><body>' . $body . '</body></html>',
            etag: null,
            lastModified: null,
        );
    }

    public function testParsesAbsoluteIconLink(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="icon" href="https://cdn.example.com/fav.png">'),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://cdn.example.com/fav.png', $icons[1]);
    }

    public function testResolvesRelativeIconAgainstTheFinalUrl(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page(/** @lang TEXT */ '<link rel="shortcut icon" href="/assets/icon.png">'),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/assets/icon.png', $icons[1]);
    }

    public function testPrefersTheLargestDeclaredSize(): void
    {
        $fetcher = new StubFeedFetcher();
        /** @noinspection HtmlUnknownTarget */
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page(
                '<link rel="icon" sizes="16x16" href="/small.png">'
                . '<link rel="icon" sizes="64x64" href="/big.png">',
            ),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/big.png', $icons[1]);
    }

    public function testRejectsInsecureIconAndFallsBackToFaviconIco(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="icon" href="http://insecure.example.com/fav.png">'),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        // A http icon is mixed-content-blocked in the https app, so it is
        // rejected in favour of the https /favicon.ico convention.
        self::assertSame('https://blog.example.com/favicon.ico', $icons[1]);
    }

    public function testFallsBackToFaviconIcoWhenThePageDeclaresNoIcon(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://blog.example.com', $this->page('<title>No icon here</title>'));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/favicon.ico', $icons[1]);
    }

    public function testFallsBackToFaviconIcoWhenTheFetchFails(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://blog.example.com', new FeedUnreachableException('boom'));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        // Best-effort: a favicon fetch failure must never propagate to the
        // refresh that called it, so a sensible fallback is still returned.
        self::assertSame('https://blog.example.com/favicon.ico', $icons[1]);
    }

    public function testDerivesTheHostFromTheBaseIgnoringSchemeAndPath(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://news.example.com', FetchResponseModel::fetched(
            'https://news.example.com/',
            permanentRedirect: false,
            body: '<!doctype html><html lang="en"><head></head><body>x</body></html>',
            etag: null,
            lastModified: null,
        ));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'http://news.example.com/some/feed.xml']);

        // A http feed URL with a path still resolves the favicon on the https
        // host root — the app renders favicons over https only.
        self::assertSame('https://news.example.com/favicon.ico', $icons[1]);
    }

    public function testReturnsNullWhenTheBaseHasNoHost(): void
    {
        $fetcher = new StubFeedFetcher();

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'not a url']);

        self::assertNull($icons[1]);
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testResolvesManySitesInOneBatch(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://one.example.com',
            FetchResponseModel::fetched(
                'https://one.example.com',
                false,
                /** @lang TEXT */ '<link rel="icon" href="/a.png">',
                null,
                null,
            ),
        );
        $fetcher->willReturn(
            'https://two.example.com',
            FetchResponseModel::fetched('https://two.example.com', false, '<html lang="en"></html>', null, null),
        );

        $icons = $this->resolver($fetcher)->resolveAll([
            7 => 'https://one.example.com/feed',
            9 => 'https://two.example.com/feed',
        ]);

        self::assertSame('https://one.example.com/a.png', $icons[7]);
        // No <link> tag, so the /favicon.ico convention stands in.
        self::assertSame('https://two.example.com/favicon.ico', $icons[9]);
    }

    public function testAFailedHomepageFetchYieldsTheConventionalFallback(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://one.example.com', new FeedUnreachableException('boom'));

        $icons = $this->resolver($fetcher)->resolveAll([7 => 'https://one.example.com/feed']);

        self::assertSame('https://one.example.com/favicon.ico', $icons[7]);
    }

    public function testAUrlWithoutAHostYieldsNoIcon(): void
    {
        $icons = $this->resolver(new StubFeedFetcher())->resolveAll([7 => 'not a url']);

        self::assertNull($icons[7]);
    }

    /**
     * An invariant failure inside the batch fetcher itself (StubFeedFetcher's unstubbed-URL guard stands in for one)
     * must not escape resolveAll(): every site still gets the /favicon.ico fallback.
     */
    public function testABatchLevelFailureDegradesEverySiteToTheFallback(): void
    {
        // No stubs configured at all, so the very first ticket makes the
        // generator throw instead of yielding a FetchOutcomeModel.
        $fetcher = new StubFeedFetcher();

        $icons = $this->resolver($fetcher)->resolveAll([
            7 => 'https://one.example.com/feed',
            9 => 'https://two.example.com/feed',
        ]);

        self::assertSame('https://one.example.com/favicon.ico', $icons[7]);
        self::assertSame('https://two.example.com/favicon.ico', $icons[9]);
    }

    public function testABatchLevelFailureIsLoggedWithItsException(): void
    {
        $logger = new RecordingLogger();

        (new FaviconResolver(new StubFeedFetcher(), $logger))->resolveAll([7 => 'https://one.example.com/feed']);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('Favicon batch fetch failed', $logger->records[0]['message']);
        self::assertInstanceOf(\Throwable::class, $logger->records[0]['context']['exception'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function lemmySiteRecords(): iterable
    {
        yield 'the site record carries the icon' => [
            '{"id":1,"name":"Lemmy Example","icon":"https://lemmy.example.com/pictrs/image/site.png"}',
            '',
            'https://lemmy.example.com/pictrs/image/site.png',
        ];
        yield 'a brace in the sidebar does not hide the icon' => [
            '{"id":1,"sidebar":"Rules: {be nice} \"quoted\"","icon":"https://lemmy.example.com/site.png"}',
            '',
            'https://lemmy.example.com/site.png',
        ];
        yield 'a long sidebar dense with escapes does not exhaust the JIT stack' => [
            '{"id":1,"sidebar":"' . str_repeat('Be \"polite\". ', 10000)
                . '","icon":"https://lemmy.example.com/site.png"}',
            '',
            'https://lemmy.example.com/site.png',
        ];
        yield 'a site without an icon never borrows another record\'s' => [
            '{"id":1,"name":"Lemmy Example"},"community":{"icon":"https://lemmy.example.com/c.png"}',
            '',
            'https://lemmy.example.com/favicon.ico',
        ];
        yield 'an insecure site icon falls back to /favicon.ico' => [
            '{"id":1,"icon":"http://lemmy.example.com/site.png"}',
            '',
            'https://lemmy.example.com/favicon.ico',
        ];
        yield 'an advertised https icon outranks the site record' => [
            '{"id":1,"icon":"https://lemmy.example.com/site.png"}',
            '<link rel="icon" href="/static/icon.png">',
            'https://lemmy.example.com/static/icon.png',
        ];
    }

    #[DataProvider('lemmySiteRecords')]
    public function testALemmyInstanceIconComesFromItsEmbeddedSiteRecord(
        string $siteRecord,
        string $extraHead,
        string $expectedIcon,
    ): void {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://lemmy.example.com/c/technology', $this->lemmyPage($siteRecord, $extraHead));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://lemmy.example.com/c/technology']);

        self::assertSame($expectedIcon, $icons[1]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function lemmyActorRecords(): iterable
    {
        $communityFragment = static fn (string $record): string => ',"community_res":{"community_view":{"community":'
            . $record . ',"subscribed":"NotSubscribed"}}';
        $personFragment = static fn (string $record): string => ',"person_res":{"person_view":{"person":'
            . $record . ',"counts":{"post_count":1}}}';
        $mouseBody = '<svg><use xlink:href="/static/9acd6610/assets/symbols.svg#icon-heart"></use></svg>';
        $mouse = 'https://lemmy.example.com/static/9acd6610/assets/icons/icon-96x96.png';
        $site = 'https://lemmy.example.com/pictrs/image/site.png';

        yield 'a community icon' => [
            $communityFragment('{"id":7,"name":"technology","icon":"https://lemmy.example.com/pictrs/image/c.png"}'),
            '',
            'https://lemmy.example.com/pictrs/image/c.png',
        ];
        yield 'a community without an icon falls back to the instance icon' => [
            $communityFragment('{"id":7,"name":"technology"}'),
            '',
            $site,
        ];
        yield 'braces and quotes in a community description do not hide its icon' => [
            $communityFragment(
                '{"id":7,"description":"Rules: {be nice} \\"quoted\\"","icon":"https://lemmy.example.com/c.png"}',
            ),
            '',
            'https://lemmy.example.com/c.png',
        ];
        yield 'an insecure community icon falls back to the instance icon' => [
            $communityFragment('{"id":7,"icon":"http://lemmy.example.com/c.png"}'),
            '',
            $site,
        ];
        yield 'a user avatar' => [
            $personFragment('{"id":9,"name":"lars","avatar":"https://lemmy.example.com/pictrs/image/a.png"}'),
            '',
            'https://lemmy.example.com/pictrs/image/a.png',
        ];
        yield 'a user without an avatar gets the lemmy-ui mouse' => [
            $personFragment('{"id":9,"name":"lars"}'),
            $mouseBody,
            $mouse,
        ];
        yield 'braces in a user bio do not hide the missing avatar' => [
            $personFragment('{"id":9,"bio":"likes {braces}"}'),
            $mouseBody,
            $mouse,
        ];
        yield 'a user without an avatar on a page with no static build falls back to the instance icon' => [
            $personFragment('{"id":9,"name":"lars"}'),
            '',
            $site,
        ];
    }

    #[DataProvider('lemmyActorRecords')]
    public function testALemmyActorIconOutranksItsInstanceIcon(
        string $actorFragment,
        string $body,
        string $expectedIcon,
    ): void {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://lemmy.example.com/c/technology',
            $this->lemmyPage(
                '{"id":1,"icon":"https://lemmy.example.com/pictrs/image/site.png"}',
                '',
                $actorFragment,
                $body,
            ),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://lemmy.example.com/c/technology']);

        self::assertSame($expectedIcon, $icons[1]);
    }

    public function testADataUriIconCostsNoOtherSiteInTheBatchItsIcon(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://one.example.com',
            $this->page('<link rel="icon" href="data:image/png;base64,iVBORw0KGgo=">', 'https://one.example.com/'),
        );
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="icon" href="https://cdn.example.com/fav.png">'),
        );

        $icons = $this->resolver($fetcher)->resolveAll([
            1 => 'https://one.example.com/',
            2 => 'https://blog.example.com/',
        ]);

        self::assertSame('https://one.example.com/favicon.ico', $icons[1]);
        self::assertSame('https://cdn.example.com/fav.png', $icons[2]);
    }

    public function testFeedsOnOneSiteShareASingleHomepageFetch(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://blog.example.com', $this->page('<link rel="icon" href="/fav.png">'));

        $icons = $this->resolver($fetcher)->resolveAll([
            1 => 'https://blog.example.com/blog/one',
            2 => 'https://blog.example.com/blog/two',
        ]);

        self::assertSame(['https://blog.example.com'], $fetcher->fetchedUrls);
        self::assertSame('https://blog.example.com/fav.png', $icons[1]);
        self::assertSame('https://blog.example.com/fav.png', $icons[2]);
    }

    public function testALemmyCommunityFeedFetchesItsCommunityPage(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://lemmy.example.com/c/technology',
            $this->lemmyPage(
                '{"id":1}',
                '',
                ',"community_res":{"community_view":{"community":{"id":7,"icon":"https://lemmy.example.com/c.png"}}}',
            ),
        );

        $icons = $this->resolver($fetcher)
            ->resolveAll([1 => 'https://lemmy.example.com/feeds/c/technology.xml?sort=New']);

        self::assertSame(['https://lemmy.example.com/c/technology'], $fetcher->fetchedUrls);
        self::assertSame('https://lemmy.example.com/c.png', $icons[1]);
    }

    public function testALemmyUserFeedFetchesItsUserPage(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://lemmy.example.com/u/lars',
            $this->lemmyPage(
                '{"id":1}',
                '',
                ',"person_res":{"person_view":{"person":{"id":9,"avatar":"https://lemmy.example.com/a.png"}}}',
            ),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://lemmy.example.com/feeds/u/lars.xml']);

        self::assertSame(['https://lemmy.example.com/u/lars'], $fetcher->fetchedUrls);
        self::assertSame('https://lemmy.example.com/a.png', $icons[1]);
    }

    public function testBothFormsOfOneActorShareOneFetch(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://lemmy.example.com/c/technology',
            $this->lemmyPage(
                '{"id":1}',
                '',
                ',"community_res":{"community_view":{"community":{"id":7,"icon":"https://lemmy.example.com/c.png"}}}',
            ),
        );

        $icons = $this->resolver($fetcher)->resolveAll([
            1 => 'https://lemmy.example.com/c/technology',
            2 => 'https://lemmy.example.com/feeds/c/technology.xml',
        ]);

        self::assertSame(['https://lemmy.example.com/c/technology'], $fetcher->fetchedUrls);
        self::assertSame('https://lemmy.example.com/c.png', $icons[1]);
        self::assertSame($icons[1], $icons[2]);
    }

    public function testAnUnreachableActorPageFallsBackToTheOriginFaviconIco(): void
    {
        $icons = $this->resolver(new StubFeedFetcher())
            ->resolveAll([1 => 'https://lemmy.example.com/c/technology']);

        self::assertSame('https://lemmy.example.com/favicon.ico', $icons[1]);
    }

    private function lemmyPage(
        string $siteRecord,
        string $extraHead,
        string $actorFragment = '',
        string $body = '',
    ): FetchResponseModel {
        $escapedSlash = '\\' . 'u002F';

        return $this->page(
            '<link rel="apple-touch-icon" href="data:image/png;base64,iVBORw0KGgo=">' . $extraHead
            . '<script>window.isoData = {"path":"/","site_res":{"site_view":{"site":'
            . str_replace('/', $escapedSlash, $siteRecord)
            . ',"local_site":{"id":1}}}' . str_replace('/', $escapedSlash, $actorFragment)
            . ',"errorPageData":undefined};</script>',
            'https://lemmy.example.com/',
            $body,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSizes(): iterable
    {
        yield 'a unit after the height' => ['64x64px'];
        yield 'a prefix before the width' => ['x64x64'];
    }

    #[DataProvider('malformedSizes')]
    public function testASizesTokenThatIsNotExactlyWidthByHeightScoresNothing(string $sizes): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page(
                '<link rel="icon" sizes="8x8" href="/small.png">'
                . '<link rel="icon" sizes="' . $sizes . '" href="/malformed.png">',
            ),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/small.png', $icons[1]);
    }
}
