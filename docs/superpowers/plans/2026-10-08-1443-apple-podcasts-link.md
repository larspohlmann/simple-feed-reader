# #1443 Apple Podcasts Show Link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A show or episode link copied from Apple Podcasts, pasted into "Add feed", subscribes to the show's RSS feed straight away.

**Architecture:** The Substack special case in `FeedDiscovery::discover()` becomes a tagged list of share-link resolvers behind `ShareLinkFeedInterface` (`feedUrl(string $enteredUrl): ?string`, first non-null wins). `ApplePodcastShowFeed` is the second resolver: it reads the show id out of the link, asks Apple's public lookup API through the SSRF-guarded `FeedFetcherInterface`, and hands back `results[0].feedUrl`, which discovery then fetches and parses exactly as a pasted feed address. The dialog's URL field gains a one-line hint naming Apple Podcasts.

**Tech Stack:** PHP 8.4 / Symfony 7.4 (`#[AutowireIterator]`, `_instanceof` tags), PHPUnit 12 with `StubFeedFetcher`, Angular 20 + Transloco, Jest in the Docker frontend container.

**Spec:** `docs/superpowers/specs/2026-10-08-1443-apple-podcasts-link-design.md`

## Global Constraints

- Branch `feature/1443-apple-podcasts-link` exists locally and carries the spec commit, built from `origin/develop`. **Before `git checkout`, confirm no other session is mid-edit on this checkout** (`git status --short` must be clean apart from this plan file and the spec, which are also committed on the branch). Commits are `type(#1443): …`, no attribution lines.
- Backend commands run from `backend/`; frontend Jest runs **inside the container**, one process at a time: `docker compose exec -T frontend npx jest <path>`.
- PHP house style: `declare(strict_types=1)`, `final readonly class`, constructor promotion, no abbreviations (`$exception`, never `$e`; `$match`, never `$m`), no boolean parameters, guard clauses. Default to no comment; one line at most where a reader would otherwise get the code wrong. Delete any comment that restates the next line.
- Every touched `src` file is PHPMD-clean: `vendor/bin/phpmd <file> text phpmd.xml.dist` prints nothing.
- `composer stan` needs a warm dev cache: run `bin/console cache:warmup` once before the first `composer check`.
- `composer infection:diff` reads committed changes against `origin/develop` and ignores untracked files: commit before running it.
- Infection's `minMsi` is a ratchet: never lower it. An escaped mutant means a missing assertion, not a config change.
- Every i18n key is added to **both** `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`, at the same position.
- Prettier `printWidth` is 100: `npx prettier --write <files>` (from `frontend/`) on every touched frontend file before committing.
- Verified on 2026-10-08 and relied on throughout: the lookup API `https://itunes.apple.com/lookup?id=<showId>` needs no key, answers `SimpleFeedReader/1.0` with HTTP 200 and content type `text/javascript`, returns `{"resultCount":0,"results":[]}` for an unknown id, and `kind: "software"` with no `feedUrl` for an App Store id. The fetcher gates on neither content type nor the 5 MB cap for a lookup body.

## File map

| File | Change |
|---|---|
| `backend/src/Service/Discovery/ShareLinkFeed/ShareLinkFeedInterface.php` | New: the resolver contract |
| `backend/src/Service/Discovery/ShareLinkFeed/SubstackProfileFeed.php` | Moved from `Service/Discovery/`, implements the interface |
| `backend/src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php` | New: the Apple resolver |
| `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php` | Takes `iterable<ShareLinkFeedInterface>` instead of `SubstackProfileFeed` |
| `backend/config/services.yaml` | `_instanceof` tag `app.share_link_feed` |
| `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` | Builds discovery with an explicit resolver list |
| `backend/tests/Service/Discovery/ShareLinkFeed/SubstackProfileFeedTest.php` | Moved from `tests/Service/Discovery/` |
| `backend/tests/Service/Discovery/ShareLinkFeed/SubstackProfileDiscoveryTest.php` | Moved from `tests/Service/Discovery/` |
| `backend/tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php` | New: wiring and first-wins order |
| `backend/tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php` | New: the resolver's unit test |
| `backend/tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowDiscoveryTest.php` | New: discovery subscribes what Apple resolves |
| `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.html` | `[hint]` on the URL field |
| `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts` | Hint test; one selector narrowed |
| `frontend/public/i18n/en.json`, `de.json` | `dialog.addFeed.urlHint` |
| `README.md` | One Listening bullet |

---

### Task 1: Share-link resolvers become a tagged list

**Files:**
- Create: `backend/src/Service/Discovery/ShareLinkFeed/ShareLinkFeedInterface.php`
- Move: `backend/src/Service/Discovery/SubstackProfileFeed.php` → `backend/src/Service/Discovery/ShareLinkFeed/SubstackProfileFeed.php`
- Modify: `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php:16,51,59`
- Modify: `backend/config/services.yaml:58-59`
- Modify: `backend/tests/Service/Discovery/BuildsFeedDiscovery.php`
- Move: `backend/tests/Service/Discovery/SubstackProfileFeedTest.php` and `SubstackProfileDiscoveryTest.php` → `backend/tests/Service/Discovery/ShareLinkFeed/`
- Create: `backend/tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php`

**Interfaces:**
- Produces: `App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface::feedUrl(string $enteredUrl): ?string`; container tag `app.share_link_feed`; `FeedDiscovery::__construct(FeedFetcherInterface $fetcher, FeedParser $parser, HtmlItemExtractor $extractor, FeedLinkScanner $links, WellKnownFeedProbe $wellKnownFeeds, BotChallengePage $botChallenge, iterable $shareLinks, iterable $offers)`; test helper `BuildsFeedDiscovery::discoveryResolving(StubFeedFetcher $fetcher, array $shareLinks): FeedDiscovery`.

- [ ] **Step 1: Write the failing wiring test**

Create `backend/tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\FeedDiscovery\FeedDiscovery;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface;
use App\Service\Discovery\ShareLinkFeed\SubstackProfileFeed;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** An untagged interface leaves #[AutowireIterator] empty without an error; this reads what discovery really got. */
final class ShareLinkFeedsAreConsultedTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    public function testTheContainerHandsDiscoveryEveryShareLinkResolver(): void
    {
        $discovery = self::getContainer()->get(FeedDiscovery::class);
        self::assertInstanceOf(FeedDiscovery::class, $discovery);

        $shareLinks = (new \ReflectionProperty($discovery, 'shareLinks'))->getValue($discovery);
        self::assertIsIterable($shareLinks);

        $classes = [];
        foreach ($shareLinks as $shareLink) {
            self::assertInstanceOf(ShareLinkFeedInterface::class, $shareLink);
            $classes[] = $shareLink::class;
        }

        self::assertContains(SubstackProfileFeed::class, $classes);
    }

    public function testTheFirstResolverToAnswerWinsAndTheRestAreNotAsked(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);
        $fetcher = $this->fetcherReturning('https://first.example/feed', 'https://first.example/feed', $xml);
        $second = new class implements ShareLinkFeedInterface {
            /** @var list<string> */
            public array $asked = [];

            public function feedUrl(string $enteredUrl): ?string
            {
                $this->asked[] = $enteredUrl;

                return null;
            }
        };

        $result = $this->discoveryResolving($fetcher, [$this->answering('https://first.example/feed'), $second])
            ->discover('https://share.example/show', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://first.example/feed', $result->feed->url);
        self::assertSame([], $second->asked);
    }

    public function testWhenNoResolverAnswersTheEnteredUrlIsFetched(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);
        $fetcher = $this->fetcherReturning('https://plain.example/feed', 'https://plain.example/feed', $xml);

        $result = $this->discoveryResolving($fetcher, [$this->answering(null), $this->answering(null)])
            ->discover('https://plain.example/feed', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame(['https://plain.example/feed'], $fetcher->fetchedUrls);
    }

    private function answering(?string $feedUrl): ShareLinkFeedInterface
    {
        return new readonly class($feedUrl) implements ShareLinkFeedInterface {
            public function __construct(private ?string $feedUrl)
            {
            }

            public function feedUrl(string $enteredUrl): ?string
            {
                return $this->feedUrl;
            }
        };
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php`
Expected: errors, `ShareLinkFeedInterface` does not exist.

- [ ] **Step 3: Create the interface**

Create `backend/src/Service/Discovery/ShareLinkFeed/ShareLinkFeedInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

/**
 * The feed a platform's share link points at, resolved before anything is fetched. A null means "not my link" or
 * "could not resolve", and a link that is not this resolver's shape costs no fetch; discovery then continues with
 * the entered URL.
 */
interface ShareLinkFeedInterface
{
    public function feedUrl(string $enteredUrl): ?string;
}
```

- [ ] **Step 4: Move the Substack resolver into the folder and implement the interface**

```bash
git mv src/Service/Discovery/SubstackProfileFeed.php src/Service/Discovery/ShareLinkFeed/SubstackProfileFeed.php
git mv tests/Service/Discovery/SubstackProfileFeedTest.php tests/Service/Discovery/ShareLinkFeed/SubstackProfileFeedTest.php
git mv tests/Service/Discovery/SubstackProfileDiscoveryTest.php tests/Service/Discovery/ShareLinkFeed/SubstackProfileDiscoveryTest.php
```

In `src/Service/Discovery/ShareLinkFeed/SubstackProfileFeed.php` change the namespace and the class line:

```php
namespace App\Service\Discovery\ShareLinkFeed;
```

```php
final readonly class SubstackProfileFeed implements ShareLinkFeedInterface
```

The rest of the file stays as it is; its `feedUrl(string $enteredUrl): ?string` already matches the interface.

In both moved tests change the namespace to `App\Tests\Service\Discovery\ShareLinkFeed`, the `use App\Service\Discovery\SubstackProfileFeed;` line to `use App\Service\Discovery\ShareLinkFeed\SubstackProfileFeed;`, and in `SubstackProfileDiscoveryTest` add `use App\Tests\Service\Discovery\BuildsFeedDiscovery;` (the trait is no longer in the same namespace) and change the fixture path from `__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml'` to `__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml'`.

- [ ] **Step 5: FeedDiscovery takes the list**

In `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php` replace the import `use App\Service\Discovery\SubstackProfileFeed;` with `use App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface;` (keep the imports sorted alphabetically as phpcs expects). Replace the constructor's docblock and the `SubstackProfileFeed` parameter:

```php
    /**
     * @param iterable<ShareLinkFeedInterface> $shareLinks
     * @param iterable<FeedOfferInterface>     $offers
     */
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
        private FeedLinkScanner $links,
        private WellKnownFeedProbe $wellKnownFeeds,
        private BotChallengePage $botChallenge,
        #[AutowireIterator('app.share_link_feed')]
        private iterable $shareLinks,
        #[AutowireIterator('app.feed_offer')]
        private iterable $offers,
    ) {
    }
```

Replace the first line of `discover()`:

```php
        $url = $this->shareLinkFeedUrl($url) ?? $url;
```

Add, directly below `discover()`:

```php
    private function shareLinkFeedUrl(string $enteredUrl): ?string
    {
        foreach ($this->shareLinks as $shareLink) {
            $feedUrl = $shareLink->feedUrl($enteredUrl);
            if (null !== $feedUrl) {
                return $feedUrl;
            }
        }

        return null;
    }
```

- [ ] **Step 6: Tag the interface**

In `backend/config/services.yaml`, inside `_instanceof`, directly above the `App\Service\Discovery\FeedOffer\FeedOfferInterface:` entry, add:

```yaml
        App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface:
            tags: ['app.share_link_feed']
```

- [ ] **Step 7: The test builder takes the resolver list**

In `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` replace the import `use App\Service\Discovery\SubstackProfileFeed;` with `use App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface;` and `use App\Service\Discovery\ShareLinkFeed\SubstackProfileFeed;`, and replace the `discovery()` method with:

```php
    private function discovery(StubFeedFetcher $fetcher): FeedDiscovery
    {
        return $this->discoveryResolving($fetcher, [new SubstackProfileFeed($fetcher)]);
    }

    /** @param list<ShareLinkFeedInterface> $shareLinks */
    private function discoveryResolving(StubFeedFetcher $fetcher, array $shareLinks): FeedDiscovery
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        return new FeedDiscovery(
            $fetcher,
            $parser,
            $extractor,
            new FeedLinkScanner(),
            new WellKnownFeedProbe($fetcher, $parser),
            new BotChallengePage(),
            $shareLinks,
            [new WordPressRestProbe($fetcher), new SoundCloudProfileFeed()],
        );
    }
```

- [ ] **Step 8: Run the discovery tests**

Run: `php bin/phpunit tests/Service/Discovery`
Expected: all green, including the two moved Substack tests and the three new ones.

- [ ] **Step 9: Lint**

```bash
vendor/bin/phpcs -q src/Service/Discovery/ShareLinkFeed src/Service/Discovery/FeedDiscovery/FeedDiscovery.php tests/Service/Discovery
vendor/bin/phpmd src/Service/Discovery/ShareLinkFeed/SubstackProfileFeed.php,src/Service/Discovery/FeedDiscovery/FeedDiscovery.php text phpmd.xml.dist
bin/console cache:warmup && composer stan
```

Expected: no output from phpcs and phpmd; PHPStan reports no errors (the `ServiceRoleRule` accepts the interface folder because the folder name equals the interface name minus `Interface`).

- [ ] **Step 10: Commit**

```bash
git add -A src/Service/Discovery tests/Service/Discovery config/services.yaml
git commit -m "refactor(#1443): share-link resolvers are a tagged list; Substack is its first implementation"
```

---

### Task 2: The Apple resolver

**Files:**
- Create: `backend/src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php`
- Create: `backend/tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php`

**Interfaces:**
- Consumes: `ShareLinkFeedInterface` (Task 1), `FeedFetcherInterface::fetch(string $url): FetchResponseModel` (throws `FetchException`), `FetchResponseModel::modifiedBody(): string`, `App\Tests\Support\StubFeedFetcher` (`willReturn`, `willThrow`, `fetchedUrls`).
- Produces: `App\Service\Discovery\ShareLinkFeed\ApplePodcastShowFeed` with `__construct(FeedFetcherInterface $fetcher)` and `feedUrl(string $enteredUrl): ?string`; the lookup URL it fetches is exactly `https://itunes.apple.com/lookup?id=<digits>`.

The spec names the show id as `?int`; the plan keeps it a digit string so a long id never passes through an integer cast. The regex guarantees digits.

- [ ] **Step 1: Write the failing unit test**

Create `backend/tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\ApplePodcastShowFeed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplePodcastShowFeedTest extends TestCase
{
    private const string SHOW_LINK = 'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894';

    /** Apple's lookup answer on 2026-10-08, trimmed to the fields a resolver may read. */
    private const string LOOKUP_BODY = /** @lang JSON */ <<<'JSON'
        {"resultCount":1,"results":[{"wrapperType":"track","kind":"podcast","collectionId":1092957894,
        "collectionName":"Lage der Nation - der Politik-Podcast aus Berlin",
        "feedUrl":"https://feeds.lagedernation.org/feeds/ldn-mp3.xml",
        "artworkUrl600":"https://is1-ssl.mzstatic.com/image/thumb/x/600x600bb.jpg"}]}
        JSON;

    public function testResolvesAShowLinkToTheFeedTheLookupNames(): void
    {
        $fetcher = $this->fetcherReturningBody('1092957894', self::LOOKUP_BODY);

        self::assertSame(
            'https://feeds.lagedernation.org/feeds/ldn-mp3.xml',
            (new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK),
        );
    }

    /** The lookup API is asked about exactly the id in the path, and nothing else. */
    public function testQueriesTheLookupApiForThatShowId(): void
    {
        $fetcher = $this->fetcherResolving('1092957894', 'https://feeds.example.org/show.xml');

        (new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK);

        self::assertSame(['https://itunes.apple.com/lookup?id=1092957894'], $fetcher->fetchedUrls);
    }

    #[DataProvider('showLinks')]
    public function testResolvesEveryShapeOfShowLink(string $enteredUrl, string $showId): void
    {
        $fetcher = $this->fetcherResolving($showId, 'https://feeds.example.org/show.xml');

        self::assertSame(
            'https://feeds.example.org/show.xml',
            (new ApplePodcastShowFeed($fetcher))->feedUrl($enteredUrl),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function showLinks(): iterable
    {
        yield 'storefront and slug' => [self::SHOW_LINK, '1092957894'];
        yield 'storefront without a slug' => ['https://podcasts.apple.com/de/podcast/id1092957894', '1092957894'];
        yield 'no storefront' => ['https://podcasts.apple.com/podcast/lage-der-nation/id1092957894', '1092957894'];
        yield 'the itunes host' => ['https://itunes.apple.com/us/podcast/lage-der-nation/id1092957894', '1092957894'];
        yield 'a mixed-case host' => ['https://Podcasts.Apple.com/de/podcast/lage-der-nation/id1092957894', '1092957894'];
        yield 'a trailing slash' => ['https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894/', '1092957894'];
        yield 'an episode link resolves to its show' => [
            'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894?i=1000700000001',
            '1092957894',
        ];
        yield 'the share link with its tracking query' => [
            'https://podcasts.apple.com/us/podcast/lage-der-nation/id1092957894?uo=4&l=en-GB',
            '1092957894',
        ];
        yield 'a different show' => ['https://podcasts.apple.com/gb/podcast/the-rest-is-x/id1611374685', '1611374685'];
    }

    #[DataProvider('nonShowLinks')]
    public function testLeavesEverythingElseAloneWithoutAskingTheApi(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull((new ApplePodcastShowFeed($fetcher))->feedUrl($enteredUrl));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string}> */
    public static function nonShowLinks(): iterable
    {
        yield 'an App Store link on the itunes host' => ['https://itunes.apple.com/de/app/podcasts/id525463029'];
        yield 'an App Store link on its own host' => ['https://apps.apple.com/de/app/podcasts/id525463029'];
        yield 'an id with letters' => ['https://podcasts.apple.com/de/podcast/lage-der-nation/id12ab'];
        yield 'a path without an id' => ['https://podcasts.apple.com/de/podcast/lage-der-nation'];
        yield 'an Apple page that is not a show' => ['https://podcasts.apple.com/de/browse'];
        yield 'a look-alike host' => ['https://podcasts.apple.com.evil.example/de/podcast/x/id1092957894'];
        yield 'a non-Apple host' => ['https://example.com/de/podcast/x/id1092957894'];
        yield 'a feed URL' => ['https://feeds.lagedernation.org/feeds/ldn-mp3.xml'];
    }

    #[DataProvider('unresolvableLookups')]
    public function testFallsThroughWhenTheLookupNamesNoPodcastFeed(string $body): void
    {
        $fetcher = $this->fetcherReturningBody('1092957894', $body);

        self::assertNull((new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK));
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvableLookups(): iterable
    {
        yield 'an unknown id' => ['{"resultCount":0,"results":[]}'];
        yield 'an App Store id' => ['{"resultCount":1,"results":[{"kind":"software","trackName":"Podcasts"}]}'];
        yield 'a podcast without a feed' => ['{"resultCount":1,"results":[{"kind":"podcast"}]}'];
        yield 'a feed that is not a string' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":42}]}'];
        yield 'an empty feed' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":""}]}'];
        yield 'a relative feed' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"/feed.xml"}]}'];
        yield 'a javascript feed' => [
            '{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"javascript:alert(1)"}]}',
        ];
        yield 'an ftp feed' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"ftp://x.example/feed"}]}'];
        yield 'results that are not a list' => ['{"resultCount":1,"results":"https://x.example/feed"}'];
        yield 'the body is not JSON at all' => [/** @lang TEXT */ '<!doctype html><html><body>Lookup</body></html>'];
        yield 'the body is a bare JSON scalar' => ['"1092957894"'];
    }

    /** An unreachable or refusing API degrades to "not resolved", never an error. */
    public function testFallsThroughWhenTheLookupApiCannotBeReached(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(
            'https://itunes.apple.com/lookup?id=1092957894',
            new FeedUnreachableException('x: HTTP 503', 503),
        );

        self::assertNull((new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK));
    }

    private function fetcherResolving(string $showId, string $feedUrl): StubFeedFetcher
    {
        return $this->fetcherReturningBody(
            $showId,
            (string) json_encode(
                ['resultCount' => 1, 'results' => [['kind' => 'podcast', 'feedUrl' => $feedUrl]]],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function fetcherReturningBody(string $showId, string $body): StubFeedFetcher
    {
        $apiUrl = sprintf('https://itunes.apple.com/lookup?id=%s', $showId);
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn($apiUrl, FetchResponseModel::fetched(
            $apiUrl,
            permanentRedirect: false,
            body: $body,
            etag: null,
            lastModified: null,
        ));

        return $fetcher;
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `php bin/phpunit tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php`
Expected: errors, `ApplePodcastShowFeed` does not exist.

- [ ] **Step 3: Write the resolver**

Create `backend/src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;

/**
 * Maps an Apple Podcasts show or episode share link to the show's RSS feed through Apple's public lookup API. Every
 * failure is a null, and discovery still fetches and parses the result, so this can only ever add a subscription.
 */
final readonly class ApplePodcastShowFeed implements ShareLinkFeedInterface
{
    private const array SHARE_HOSTS = ['podcasts.apple.com', 'itunes.apple.com'];

    /** `/<storefront>/podcast/<slug>/id<showId>`; the storefront and the slug are optional, the query is ignored. */
    private const string SHOW_PATH = '#^/(?:[a-z]{2}/)?podcast/(?:[^/]+/)?id(\d+)/?$#i';

    private const string LOOKUP_API = 'https://itunes.apple.com/lookup?id=%s';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function feedUrl(string $enteredUrl): ?string
    {
        $showId = $this->showId($enteredUrl);

        return null === $showId ? null : $this->lookedUpFeedUrl($showId);
    }

    private function showId(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        if (!\in_array($host, self::SHARE_HOSTS, true)) {
            return null;
        }

        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);

        return 1 === preg_match(self::SHOW_PATH, $path, $match) ? $match[1] : null;
    }

    private function lookedUpFeedUrl(string $showId): ?string
    {
        try {
            $response = $this->fetcher->fetch(sprintf(self::LOOKUP_API, $showId));
        } catch (FetchException) {
            return null;
        }

        return $this->feedUrlOf($response->modifiedBody());
    }

    /** Reads and validates `results[0].feedUrl` out of a lookup body whose first result is a podcast. */
    private function feedUrlOf(string $lookupJson): ?string
    {
        $lookup = json_decode($lookupJson, true);
        $results = \is_array($lookup) ? ($lookup['results'] ?? null) : null;
        $show = \is_array($results) ? ($results[0] ?? null) : null;
        if (!\is_array($show) || 'podcast' !== ($show['kind'] ?? null)) {
            return null;
        }

        $feedUrl = $show['feedUrl'] ?? null;

        return \is_string($feedUrl) && $this->isAbsoluteHttpUrl($feedUrl) ? $feedUrl : null;
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return '' !== (string) parse_url($url, PHP_URL_HOST) && \in_array($scheme, ['http', 'https'], true);
    }
}
```

- [ ] **Step 4: Run the unit test**

Run: `php bin/phpunit tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php`
Expected: PASS, every data-provider row green. If a `nonShowLinks` row resolves, the host or path check is too loose; if a `showLinks` row returns null, check that the row's path really matches `SHOW_PATH` before loosening it.

- [ ] **Step 5: Lint**

```bash
vendor/bin/phpcs -q src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php
vendor/bin/phpmd src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php text phpmd.xml.dist
composer stan
```

Expected: no phpcs or phpmd output, PHPStan clean. If PHPStan complains that `$match[1]` may be undefined, keep the `1 === preg_match(...)` guard and add nothing else; it narrows the type.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeedTest.php
git commit -m "feat(#1443): an Apple Podcasts show link resolves to the show's feed through Apple's lookup API"
```

---

### Task 3: Discovery subscribes what Apple resolves

**Files:**
- Modify: `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` (the default resolver list)
- Modify: `backend/tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php` (the container assertion)
- Create: `backend/tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowDiscoveryTest.php`

**Interfaces:**
- Consumes: `ApplePodcastShowFeed` (Task 2), `BuildsFeedDiscovery::discovery()`, `fetcher()`, `fetcherReturning()` (Task 1).

- [ ] **Step 1: Write the failing discovery test**

Create `backend/tests/Service/Discovery/ShareLinkFeed/ApplePodcastShowDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves FeedDiscovery consults ApplePodcastShowFeed, then fetches and parses what it resolves; the resolver's own
 * edge cases are ApplePodcastShowFeedTest's.
 */
final class ApplePodcastShowDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    private const string SHOW_LINK = 'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894?i=1000700000001';

    private const string LOOKUP_URL = 'https://itunes.apple.com/lookup?id=1092957894';

    /** The feed lives on the publisher's host, which nothing about the Apple link names; the episode query is dropped. */
    public function testAShowLinkSubscribesTheFeedTheLookupResolves(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcherReturning(
            'https://feeds.example.org/show.xml',
            'https://feeds.example.org/show.xml',
            $xml,
        );
        $this->stubLookup($fetcher, '{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"https://feeds.example.org/show.xml"}]}');

        $result = $this->discovery($fetcher)->discover(self::SHOW_LINK, ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://feeds.example.org/show.xml', $result->feed->url);
        self::assertSame([], $result->candidates);
        self::assertNull($result->scrapeFailureReason);
    }

    /** An id Apple does not know must not fabricate a subscription: discovery falls through to the ordinary path. */
    public function testAnUnresolvableShowLinkFallsThroughInsteadOfSubscribing(): void
    {
        $fetcher = $this->fetcher();
        $this->stubLookup($fetcher, '{"resultCount":0,"results":[]}');

        $result = $this->discovery($fetcher)->discover(self::SHOW_LINK, ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertSame([], $result->candidates);
        self::assertContains(self::SHOW_LINK, $fetcher->fetchedUrls);
    }

    private function stubLookup(StubFeedFetcher $fetcher, string $body): void
    {
        $fetcher->willReturn(
            self::LOOKUP_URL,
            FetchResponseModel::fetched(self::LOOKUP_URL, permanentRedirect: false, body: $body, etag: null, lastModified: null),
        );
    }
}
```

Then in `ShareLinkFeedsAreConsultedTest::testTheContainerHandsDiscoveryEveryShareLinkResolver()` add, after the Substack assertion:

```php
        self::assertContains(ApplePodcastShowFeed::class, $classes);
```

with `use App\Service\Discovery\ShareLinkFeed\ApplePodcastShowFeed;` among the imports.

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Discovery/ShareLinkFeed`
Expected: `ApplePodcastShowDiscoveryTest::testAShowLinkSubscribesTheFeedTheLookupResolves` fails (the stub discovery has no Apple resolver, so the Apple link itself is fetched and 404s). The container test passes already, because `services.yaml` autoconfigures every `ShareLinkFeedInterface` implementation; keep its assertion anyway, it is what proves the tag survives a rename.

- [ ] **Step 3: Wire the resolver into the test builder**

In `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` add `use App\Service\Discovery\ShareLinkFeed\ApplePodcastShowFeed;` and change `discovery()`:

```php
    private function discovery(StubFeedFetcher $fetcher): FeedDiscovery
    {
        return $this->discoveryResolving(
            $fetcher,
            [new SubstackProfileFeed($fetcher), new ApplePodcastShowFeed($fetcher)],
        );
    }
```

- [ ] **Step 4: Run the whole discovery folder**

Run: `php bin/phpunit tests/Service/Discovery`
Expected: PASS. `FeedDiscoveryTest` and the SoundCloud and Substack discovery tests are unaffected: none of their URLs is on an Apple host.

- [ ] **Step 5: Lint and commit**

```bash
vendor/bin/phpcs -q tests/Service/Discovery
git add tests/Service/Discovery
git commit -m "test(#1443): discovery subscribes the feed Apple's lookup resolves and falls through when it resolves nothing"
```

---

### Task 4: The dialog says Apple Podcasts links work

**Files:**
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.html:7`
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts:509`
- Modify: `frontend/public/i18n/en.json:711`, `frontend/public/i18n/de.json:711`

**Interfaces:**
- Consumes: `FieldComponent.hint` input (`frontend/src/app/shared/field/field.component.ts:25`), rendered as `<p class="hint">` inside `app-field`.

- [ ] **Step 1: Write the failing spec and narrow the colliding selector**

In `add-feed-dialog.component.spec.ts`, after the test `'reports that a search is running while the subscribe is in flight'` add:

```ts
  it('names Apple Podcasts share links beside the URL field', () => {
    // Nobody would try pasting a show link unless the field says it works.
    const fixture = create();

    expect((fixture.nativeElement as HTMLElement).querySelector('app-field .hint')!.textContent).toContain(
      'Apple Podcasts',
    );
  });
```

The field hint is also a `<p class="hint">`, and it comes first in DOM order, so the existing empty-state test at line 509 must stop asking for the first `.hint`. Change that line to:

```ts
    expect((fixture.nativeElement as HTMLElement).querySelector('.fields > .hint')!.textContent).toContain(
```

- [ ] **Step 2: Run the spec to see the new test fail**

Run (from the repo root): `docker compose exec -T frontend npx jest src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts`
Expected: one failure, `querySelector('app-field .hint')` is null.

- [ ] **Step 3: Add the hint and its strings**

In `add-feed-dialog.component.html` change the field opening tag to:

```html
      <app-field
        [label]="'dialog.addFeed.urlLabel' | transloco"
        [hint]="'dialog.addFeed.urlHint' | transloco"
        [error]="error()"
      >
```

In `frontend/public/i18n/en.json`, directly after the `"urlLabel"` line inside `"addFeed"`, add:

```json
      "urlHint": "A website or feed address, or a show link copied from Apple Podcasts.",
```

In `frontend/public/i18n/de.json`, at the same position:

```json
      "urlHint": "Eine Website- oder Feed-Adresse, oder ein aus Apple Podcasts kopierter Sendungslink.",
```

- [ ] **Step 4: Run the spec and the dictionary parity spec**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts src/app/core/i18n/i18n-dictionaries.spec.ts`
Expected: PASS.

- [ ] **Step 5: Format and run the frontend gate**

```bash
cd frontend && npx prettier --write src/app/reader/feeds/add-feed/add-feed-dialog.component.html src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts public/i18n/en.json public/i18n/de.json && cd ..
docker compose exec -T frontend npm run check
```

Expected: ESLint, Prettier, Stylelint and Jest all green.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.html frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1443): the Add feed field says a show link copied from Apple Podcasts works"
```

---

### Task 5: README, the gates, and the branch

**Files:**
- Modify: `README.md:79-80`

- [ ] **Step 1: The README bullet**

In `README.md`, directly after the Listening bullet that ends with `account's RSS feed.`, add:

```markdown
- Paste a show link copied from Apple Podcasts into "Add feed" and the reader
  subscribes to the show's RSS feed.
```

```bash
git add README.md
git commit -m "docs(#1443): README names the Apple Podcasts share link"
```

- [ ] **Step 2: The backend gates**

From `backend/`:

```bash
composer check
composer md
composer test:parallel
composer infection:diff
```

Expected: `composer check` and `composer md` print no findings; the suite is green; Infection reports MSI at or above `minMsi` in `infection.json5` with no escaped mutant in `ApplePodcastShowFeed.php` or `FeedDiscovery.php`. An escaped mutant on the host or path check means a `nonShowLinks` row is missing; add the row that kills it rather than touching the config.

- [ ] **Step 3: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` on `backend/src/Service/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php`, `backend/src/Service/Discovery/ShareLinkFeed/SubstackProfileFeed.php`, `backend/src/Service/Discovery/ShareLinkFeed/ShareLinkFeedInterface.php`, `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php` and the five test files under `backend/tests/Service/Discovery/ShareLinkFeed/`.
Expected: no ERROR or WARNING. Fix any; weak warnings are advisory.

- [ ] **Step 4: The MySQL leg**

The Docker php container bind-mounts the checkout but caches the compiled container, so clear it first:

```bash
docker compose exec php bin/console cache:clear
docker compose exec php composer test
```

Expected: green.

- [ ] **Step 5: Try it against the Docker stack**

With the stack up, sign in at `https://localhost:8443`, open "Add feed", confirm the hint reads "A website or feed address, or a show link copied from Apple Podcasts.", paste `https://podcasts.apple.com/de/podcast/lage-der-nation-der-politik-podcast-aus-berlin/id1092957894` and add. Expected: the subscription "Lage der Nation" appears without a candidate step. Then scan today's dev log for anything the resolver swallowed:

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq -c 'select(.level_name != "DEBUG") | {level_name, message}'
```

Expected: no warning or error from the subscribe request. Unsubscribe the show again afterwards so the dev database is left as found.

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/1443-apple-podcasts-link
gh pr create --base develop --title "Add feed: an Apple Podcasts show link subscribes to the show's RSS feed" --body "$(cat <<'EOF'
Closes #1443

A show or episode link copied from Apple Podcasts, pasted into "Add feed", subscribes to the show's RSS feed straight away. The show id in the link is resolved through Apple's public lookup API (no key), and discovery fetches and parses the `feedUrl` it names, exactly as a pasted feed address.

- The Substack special case in `FeedDiscovery` is now a tagged list of `ShareLinkFeedInterface` resolvers (`app.share_link_feed`), first non-null wins; `SubstackProfileFeed` moved into the interface folder, `ApplePodcastShowFeed` is the second implementation.
- A recognised link that does not resolve (unknown id, not a podcast, no feed, API unreachable) falls through to the ordinary discovery path, as a Substack profile does. No new failure reason.
- The URL field in "Add feed" gets a one-line hint naming Apple Podcasts; one README bullet under Listening.

Spec: `docs/superpowers/specs/2026-10-08-1443-apple-podcasts-link-design.md`. Kept open for later: typing a show's name and picking it from Apple's catalog search.
EOF
)"
```

Then bind the PR with `mcp__ccd_pr__get_status` / `mcp__ccd_pr__bind_pr`, and read its CI from there. Do not merge: merging to develop is the user's call.

---

## Self-review

- **Spec coverage.** Resolution through the lookup API, the fetcher as HTTP client, every accepted link shape, the absolute-http validation, null falls through, the tagged list with the moved Substack resolver, the test builder, the names and constants, the dialog hint with both strings, the README bullet: Tasks 1 to 5. The spec's three test files, the moved Substack tests, the dialog spec and the gates: Tasks 1 to 5. Level 2 is documentation only in the spec and needs no task.
- **Deviation from the spec, stated:** the show id stays a digit string (`showId(): ?string`, `LOOKUP_API` with `%s`) instead of `?int`, so a long id never passes through an integer cast.
- **Type consistency.** `ShareLinkFeedInterface::feedUrl(string $enteredUrl): ?string` is used by that name in Tasks 1, 2 and 3; `discoveryResolving(StubFeedFetcher, array)` is defined in Task 1 and used in Tasks 1 and 3; the reflected property is `shareLinks` in both `FeedDiscovery` (Task 1) and the wiring test (Tasks 1 and 3); the lookup URL `https://itunes.apple.com/lookup?id=1092957894` is identical in Tasks 2 and 3.
- **Placeholders.** None: every step carries its code, command and expected result.
