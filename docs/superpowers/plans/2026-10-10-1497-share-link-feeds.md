# #1497 GitHub and YouTube Links Without a Feed: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pasting a GitHub repository link, a YouTube playlist link or a YouTube video link into "Add feed" subscribes to a real feed: the repository's releases, the playlist, or the video's channel.

**Architecture:** Three more `ShareLinkFeedInterface` resolvers in `Service/Discovery/ShareLinkFeed/`. They are tagged automatically by the existing `_instanceof` rule in `services.yaml`, and `FeedDiscovery` asks them in turn; the first non-null answer wins. The GitHub and playlist resolvers rewrite the URL without fetching anything. The video resolver fetches the watch page once, through the SSRF-guarded `FeedFetcherInterface`, and reads `externalChannelId` from it. A static helper, `Discovery/Support/YouTubePlaylistLink`, decides what counts as a playlist link. Both YouTube resolvers use it, so a `watch?v=…&list=…` link always resolves to the playlist, whichever resolver the container asks first.

**Tech Stack:** PHP 8.4 / Symfony 7.4, PHPUnit 12 with `StubFeedFetcher`.

**Spec:** issue #1497 (https://github.com/larspohlmann/simple-feed-reader/issues/1497); the decisions below settle its open questions.

## Decisions (settling the issue's open questions)

- `watch?v=…&list=<id>` resolves to the **playlist**.
- Auto-generated mixes (`list=RD…`) are **not** playlists. Their feed answers 404 (measured on 2026-10-10: `playlist_id=RDEeS-cBgIoxI` returned 404, and an uploads list `UU…` returned 200). So the playlist resolver declines them, and a `watch?v=…&list=RD…` link resolves to the video's channel.
- A GitHub user or org link (`github.com/<user>`, one path segment) falls through to ordinary discovery. No activity feed.
- A GitHub URL that already ends in `.atom` (for example `…/commits/main.atom`) is left alone: it is a feed already.
- The add-feed dialog hint is not changed; the README lists the new links.

## Global Constraints

- **Before creating the branch, confirm that no other session is mid-edit on this checkout:** `git status --short` must be clean apart from this plan file. Branch: `git checkout -b feature/1497-share-link-feeds` off an up-to-date `develop`. The plan file is the first commit: `docs(#1497): implementation plan`.
- Commits are `type(#1497): …`, lower case, with no attribution lines.
- Backend commands run from `backend/`.
- House style:
  - `declare(strict_types=1)`, `final readonly class`, constructor promotion.
  - No abbreviations (`$match`, `$exception`), no boolean parameters, guard clauses.
  - Default to no comment; one line at most, and only where a reader would otherwise get the code wrong.
- Every touched `src` file must be PHPMD-clean: `vendor/bin/phpmd <file> text phpmd.xml.dist` prints nothing.
- Warm the dev cache once before the first `composer stan`: `bin/console cache:warmup`.
- `composer infection:diff` reads committed changes against `origin/develop`, so commit first. `minMsi` is a ratchet. An escaped mutant means a test row is missing: add the row that kills it, and never change the config.
- `Discovery` may import `App\Service\Reader\Media\Support\YouTubeVideoId` (its host list and its `PATTERN`). `Reader` imports nothing from `Discovery`, so no module cycle. If `ServiceModuleCycleRule` disagrees, stop and report rather than copying the constants.
- Facts measured on 2026-10-10 with `SimpleFeedReader/1.0` from the php container, and relied on below:
  - `https://github.com/<owner>/<repo>/releases.atom` answers for any repository, including ones without GitHub releases (it lists tags; `torvalds/linux`: 10 entries).
  - `https://www.youtube.com/feeds/videos.xml?playlist_id=<id>` answers for a real playlist.
  - The watch page of a video or a Short carries `"externalChannelId":"UC…"` (UC plus 22 characters).

## File map

| File | Change |
|---|---|
| `backend/src/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeed.php` | New: repo link → `releases.atom` |
| `backend/src/Service/Discovery/Support/YouTubePlaylistLink.php` | New: the playlist id of a YouTube link, or null |
| `backend/src/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeed.php` | New: playlist link → playlist feed |
| `backend/src/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeed.php` | New: video link → channel feed (one fetch) |
| `backend/tests/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeedTest.php` | New |
| `backend/tests/Service/Discovery/Support/YouTubePlaylistLinkTest.php` | New |
| `backend/tests/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeedTest.php` | New |
| `backend/tests/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeedTest.php` | New |
| `backend/tests/Service/Discovery/ShareLinkFeed/PlatformLinkDiscoveryTest.php` | New: discovery subscribes what each resolver answers |
| `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` | `discovery()` passes the three new resolvers |
| `backend/tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php` | Asserts that the container hands over the three new resolvers |
| `README.md` | Lists the new links |

---

### Task 1: GitHub repository → releases feed

**Files:**
- Create: `backend/src/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeed.php`
- Test: `backend/tests/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeedTest.php`

**Interfaces:**
- Produces: `GitHubRepositoryFeed implements ShareLinkFeedInterface`, no constructor arguments, `feedUrl(string $enteredUrl): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\GitHubRepositoryFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubRepositoryFeedTest extends TestCase
{
    #[DataProvider('repositoryLinks')]
    public function testResolvesARepositoryLinkToItsReleasesFeed(string $enteredUrl, string $feedUrl): void
    {
        self::assertSame($feedUrl, (new GitHubRepositoryFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string, string}> */
    public static function repositoryLinks(): iterable
    {
        $symfony = 'https://github.com/symfony/symfony/releases.atom';

        yield 'the repository page' => ['https://github.com/symfony/symfony', $symfony];
        yield 'a trailing slash' => ['https://github.com/symfony/symfony/', $symfony];
        yield 'the www host' => ['https://www.github.com/symfony/symfony', $symfony];
        yield 'a mixed-case host' => ['https://GitHub.com/symfony/symfony', $symfony];
        yield 'a plain http link' => ['http://github.com/symfony/symfony', $symfony];
        yield 'a file in the tree' => ['https://github.com/symfony/symfony/tree/7.4/src/Symfony', $symfony];
        yield 'the releases page' => ['https://github.com/symfony/symfony/releases', $symfony];
        yield 'an issue' => ['https://github.com/symfony/symfony/issues/12', $symfony];
        yield 'a clone address' => ['https://github.com/symfony/symfony.git', $symfony];
        yield 'a query and a fragment' => ['https://github.com/symfony/symfony?tab=readme#install', $symfony];
        yield 'a repository name with dots' => [
            'https://github.com/jquery/jquery.com',
            'https://github.com/jquery/jquery.com/releases.atom',
        ];
        yield 'a repository name starting with a dot' => [
            'https://github.com/symfony/.github',
            'https://github.com/symfony/.github/releases.atom',
        ];
        yield 'an owner with a hyphen keeps its case' => [
            'https://github.com/Lars-Pohlmann/My_Repo',
            'https://github.com/Lars-Pohlmann/My_Repo/releases.atom',
        ];
    }

    #[DataProvider('otherLinks')]
    public function testLeavesEverythingElseAlone(string $enteredUrl): void
    {
        self::assertNull((new GitHubRepositoryFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'a user or org page' => ['https://github.com/symfony'];
        yield 'the GitHub home page' => ['https://github.com/'];
        yield 'the host without a path' => ['https://github.com'];
        yield 'an org route' => ['https://github.com/orgs/symfony/repositories'];
        yield 'a user route' => ['https://github.com/users/symfony/projects'];
        yield 'the settings' => ['https://github.com/settings/profile'];
        yield 'a topic' => ['https://github.com/topics/php'];
        yield 'the marketplace' => ['https://github.com/marketplace/actions'];
        yield 'a sponsors page' => ['https://github.com/sponsors/symfony'];
        yield 'a releases feed already' => ['https://github.com/symfony/symfony/releases.atom'];
        yield 'a commits feed already' => ['https://github.com/symfony/symfony/commits/7.4.atom'];
        yield 'a parent-directory segment' => ['https://github.com/symfony/..'];
        yield 'a current-directory segment' => ['https://github.com/symfony/./x'];
        yield 'an owner starting with a hyphen' => ['https://github.com/-symfony/symfony'];
        yield 'a gist' => ['https://gist.github.com/symfony/abc123'];
        yield 'raw content' => ['https://raw.githubusercontent.com/symfony/symfony/7.4/README.md'];
        yield 'a look-alike host' => ['https://github.com.evil.example/symfony/symfony'];
        yield 'another host' => ['https://gitlab.com/symfony/symfony'];
        yield 'text that is not a URL' => ['not a url'];
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php bin/phpunit tests/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeedTest.php`
Expected: an error, because class `GitHubRepositoryFeed` is not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

/**
 * Maps any page of a GitHub repository to the repository's releases feed, which GitHub fills from the tags when the
 * repository publishes no releases. The repository page itself advertises no feed.
 */
final readonly class GitHubRepositoryFeed implements ShareLinkFeedInterface
{
    private const array HOSTS = ['github.com', 'www.github.com'];

    /** `/<owner>/<repository>`, then anything; a `.git` suffix is the clone address of the same repository. */
    private const string REPOSITORY_PATH =
        '#^/([A-Za-z0-9][A-Za-z0-9-]*)/(?!\.\.?(?:/|$))([A-Za-z0-9._-]+?)(?:\.git)?(?:/|$)#';

    /** GitHub's own top-level routes, which no account may take as its name. */
    private const array RESERVED_OWNERS = [
        'orgs', 'users', 'settings', 'topics', 'marketplace', 'sponsors', 'collections', 'explore', 'trending',
        'features', 'enterprise', 'notifications', 'search', 'login', 'pricing', 'about', 'apps', 'codespaces',
        'security', 'site', 'contact', 'customer-stories', 'events', 'new', 'pulls', 'issues', 'dashboard',
        'account', 'stars', 'watching', 'readme', 'team', 'join', 'signup',
    ];

    private const string RELEASES_FEED = 'https://github.com/%s/%s/releases.atom';

    public function feedUrl(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (!\in_array($host, self::HOSTS, true) || str_ends_with($path, '.atom')) {
            return null;
        }

        if (1 !== preg_match(self::REPOSITORY_PATH, $path, $match)) {
            return null;
        }

        return \in_array(strtolower($match[1]), self::RESERVED_OWNERS, true)
            ? null
            : sprintf(self::RELEASES_FEED, $match[1], $match[2]);
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `php bin/phpunit tests/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeedTest.php`
Expected: OK. If `a clone address` yields `symfony.git`, the lazy `+?` was lost; keep it lazy.

- [ ] **Step 5: Run lint and commit**

```bash
vendor/bin/phpmd src/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeed.php text phpmd.xml.dist
git add src/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeed.php tests/Service/Discovery/ShareLinkFeed/GitHubRepositoryFeedTest.php
git commit -m "feat(#1497): a github repository link resolves to its releases feed"
```

---

### Task 2: YouTube playlist link → playlist feed

**Files:**
- Create: `backend/src/Service/Discovery/Support/YouTubePlaylistLink.php`
- Create: `backend/src/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeed.php`
- Test: `backend/tests/Service/Discovery/Support/YouTubePlaylistLinkTest.php`
- Test: `backend/tests/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeedTest.php`

**Interfaces:**
- Consumes: `App\Service\Reader\Media\Support\YouTubeVideoId::isYouTubeComHost(string $host): bool`.
- Produces:
  - `YouTubePlaylistLink::listId(string $url): ?string` (static);
  - `YouTubePlaylistFeed implements ShareLinkFeedInterface`, no constructor arguments.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Discovery/Support/YouTubePlaylistLinkTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\Support;

use App\Service\Discovery\Support\YouTubePlaylistLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubePlaylistLinkTest extends TestCase
{
    private const string LIST = 'PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF';

    #[DataProvider('playlistLinks')]
    public function testReadsThePlaylistIdOfAPlaylistLink(string $url): void
    {
        self::assertSame(self::LIST, YouTubePlaylistLink::listId($url));
    }

    /** @return iterable<string, array{string}> */
    public static function playlistLinks(): iterable
    {
        yield 'the playlist page' => ['https://www.youtube.com/playlist?list=' . self::LIST];
        yield 'the bare host' => ['https://youtube.com/playlist?list=' . self::LIST];
        yield 'the mobile host' => ['https://m.youtube.com/playlist?list=' . self::LIST];
        yield 'a mixed-case host' => ['https://www.YouTube.com/playlist?list=' . self::LIST];
        yield 'a trailing slash' => ['https://www.youtube.com/playlist/?list=' . self::LIST];
        yield 'a video watched inside the playlist' => [
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=' . self::LIST . '&index=3',
        ];
        yield 'a share tracking parameter' => ['https://www.youtube.com/playlist?list=' . self::LIST . '&si=abc'];
    }

    #[DataProvider('otherLinks')]
    public function testFindsNoPlaylistInAnythingElse(string $url): void
    {
        self::assertNull(YouTubePlaylistLink::listId($url));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'an auto-generated mix' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI&list=RDEeS-cBgIoxI'];
        yield 'a video without a playlist' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'a playlist page without a list' => ['https://www.youtube.com/playlist'];
        yield 'an empty list' => ['https://www.youtube.com/playlist?list='];
        yield 'a list with a forbidden character' => ['https://www.youtube.com/playlist?list=PL%27x'];
        yield 'a list given twice as an array' => ['https://www.youtube.com/playlist?list[]=' . self::LIST];
        yield 'a channel page carrying a list' => ['https://www.youtube.com/@veritasium?list=' . self::LIST];
        yield 'a look-alike host' => ['https://www.youtube.com.evil.example/playlist?list=' . self::LIST];
        yield 'another host' => ['https://vimeo.com/playlist?list=' . self::LIST];
        yield 'text that is not a URL' => ['not a url'];
    }
}
```

`tests/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\YouTubePlaylistFeed;
use PHPUnit\Framework\TestCase;

final class YouTubePlaylistFeedTest extends TestCase
{
    public function testResolvesAPlaylistLinkToThePlaylistFeed(): void
    {
        self::assertSame(
            'https://www.youtube.com/feeds/videos.xml?playlist_id=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            (new YouTubePlaylistFeed())->feedUrl(
                'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            ),
        );
    }

    public function testLeavesALinkWithoutAPlaylistAlone(): void
    {
        self::assertNull((new YouTubePlaylistFeed())->feedUrl('https://www.youtube.com/watch?v=EeS-cBgIoxI'));
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php bin/phpunit tests/Service/Discovery/Support/YouTubePlaylistLinkTest.php tests/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeedTest.php`
Expected: errors, because neither class is found.

- [ ] **Step 3: Implement**

`src/Service/Discovery/Support/YouTubePlaylistLink.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\Support;

use App\Service\Reader\Media\Support\YouTubeVideoId;

final class YouTubePlaylistLink
{
    private const array PLAYLIST_PATHS = ['/playlist', '/watch'];

    private const string LIST_ID = '#^[A-Za-z0-9_-]+$#';

    /** An auto-generated mix is personal to the viewer and has no feed: YouTube answers 404. */
    private const string MIX_PREFIX = 'RD';

    public static function listId(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (!YouTubeVideoId::isYouTubeComHost($host) || !\in_array($path, self::PLAYLIST_PATHS, true)) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $listId = $query['list'] ?? null;

        return \is_string($listId) && 1 === preg_match(self::LIST_ID, $listId)
            && !str_starts_with($listId, self::MIX_PREFIX)
            ? $listId
            : null;
    }

    private function __construct()
    {
    }
}
```

`src/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Support\YouTubePlaylistLink;

/** A playlist page advertises no feed, although YouTube serves one under the playlist's id. */
final readonly class YouTubePlaylistFeed implements ShareLinkFeedInterface
{
    private const string PLAYLIST_FEED = 'https://www.youtube.com/feeds/videos.xml?playlist_id=%s';

    public function feedUrl(string $enteredUrl): ?string
    {
        $listId = YouTubePlaylistLink::listId($enteredUrl);

        return null === $listId ? null : sprintf(self::PLAYLIST_FEED, $listId);
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: the command from Step 2. Expected: OK.

- [ ] **Step 5: Run lint and commit**

```bash
vendor/bin/phpmd src/Service/Discovery/Support/YouTubePlaylistLink.php,src/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeed.php text phpmd.xml.dist
git add src/Service/Discovery/Support src/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeed.php tests/Service/Discovery/Support tests/Service/Discovery/ShareLinkFeed/YouTubePlaylistFeedTest.php
git commit -m "feat(#1497): a youtube playlist link resolves to the playlist feed"
```

---

### Task 3: YouTube video link → channel feed

**Files:**
- Create: `backend/src/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeed.php`
- Test: `backend/tests/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeedTest.php`

**Interfaces:**
- Consumes:
  - `YouTubePlaylistLink::listId(string $url): ?string` (Task 2);
  - `YouTubeVideoId::PATTERN` and `YouTubeVideoId::isYouTubeComHost()`;
  - `FeedFetcherInterface::fetch(string $url): FetchResponseModel`, which throws a `FetchException`.
- Produces: `YouTubeVideoChannelFeed implements ShareLinkFeedInterface`, constructor `(FeedFetcherInterface $fetcher)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\YouTubeVideoChannelFeed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeVideoChannelFeedTest extends TestCase
{
    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=EeS-cBgIoxI';

    private const string CHANNEL_FEED =
        'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA';

    /** The player response of the watch page on 2026-10-10, trimmed to the field a resolver may read. */
    private const string WATCH_BODY = /** @lang HTML */ <<<'HTML'
        <html><body><script>var ytInitialPlayerResponse = {"microformat":{"playerMicroformatRenderer":
        {"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA","ownerChannelName":"Milin Woolfelt"}}};</script></body></html>
        HTML;

    #[DataProvider('videoLinks')]
    public function testResolvesAVideoLinkToItsChannelFeed(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturnBody(self::WATCH_PAGE, self::WATCH_BODY);

        self::assertSame(self::CHANNEL_FEED, (new YouTubeVideoChannelFeed($fetcher))->feedUrl($enteredUrl));
        self::assertSame([self::WATCH_PAGE], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string}> */
    public static function videoLinks(): iterable
    {
        yield 'the watch page' => [self::WATCH_PAGE];
        yield 'the bare host' => ['https://youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'the mobile host' => ['https://m.youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'a mixed-case host' => ['https://www.YouTube.com/watch?v=EeS-cBgIoxI'];
        yield 'a start time' => ['https://www.youtube.com/watch?t=42s&v=EeS-cBgIoxI'];
        yield 'an auto-generated mix' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI&list=RDEeS-cBgIoxI'];
        yield 'the short link' => ['https://youtu.be/EeS-cBgIoxI'];
        yield 'the short link with a share id' => ['https://youtu.be/EeS-cBgIoxI?si=AbCdEf'];
        yield 'a mixed-case short-link host' => ['https://YouTu.be/EeS-cBgIoxI'];
        yield 'a Short' => ['https://www.youtube.com/shorts/EeS-cBgIoxI'];
        yield 'a Short with a trailing slash' => ['https://www.youtube.com/shorts/EeS-cBgIoxI/'];
        yield 'a live stream' => ['https://www.youtube.com/live/EeS-cBgIoxI'];
    }

    #[DataProvider('otherLinks')]
    public function testLeavesEverythingElseAloneWithoutFetching(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull((new YouTubeVideoChannelFeed($fetcher))->feedUrl($enteredUrl));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'a video inside a playlist' => [
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
        ];
        yield 'a playlist page' => ['https://www.youtube.com/playlist?list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF'];
        yield 'a channel page' => ['https://www.youtube.com/@veritasium'];
        yield 'a watch page without a video' => ['https://www.youtube.com/watch'];
        yield 'a video id that is too short' => ['https://www.youtube.com/watch?v=EeS-cBgIox'];
        yield 'a video id that is too long' => ['https://www.youtube.com/watch?v=EeS-cBgIoxIx'];
        yield 'a video id given as an array' => ['https://www.youtube.com/watch?v[]=EeS-cBgIoxI'];
        yield 'a short link with a sub-path' => ['https://youtu.be/EeS-cBgIoxI/extra'];
        yield 'a Short id that is too short' => ['https://www.youtube.com/shorts/EeS-cBgIox'];
        yield 'an embed on another host' => ['https://www.youtube-nocookie.com/embed/EeS-cBgIoxI'];
        yield 'a short-link path on a full host' => ['https://www.youtube.com/EeS-cBgIoxI'];
        yield 'a look-alike host' => ['https://www.youtube.com.evil.example/watch?v=EeS-cBgIoxI'];
        yield 'another host' => ['https://vimeo.com/watch?v=EeS-cBgIoxI'];
        yield 'text that is not a URL' => ['not a url'];
    }

    #[DataProvider('pagesWithoutAChannel')]
    public function testFallsThroughWhenThePageNamesNoChannel(string $body): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturnBody(self::WATCH_PAGE, $body);

        self::assertNull((new YouTubeVideoChannelFeed($fetcher))->feedUrl(self::WATCH_PAGE));
    }

    /** @return iterable<string, array{string}> */
    public static function pagesWithoutAChannel(): iterable
    {
        yield 'no channel id at all' => ['<html><body>Video unavailable</body></html>'];
        yield 'a channel id without the UC prefix' => ['{"externalChannelId":"XXZpc-xP3njReG_r4Ur5a7mA"}'];
        yield 'a channel id that is too short' => ['{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7m"}'];
        yield 'a channel id with a forbidden character' => ['{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7m\'"}'];
    }

    public function testFallsThroughWhenTheWatchPageCannotBeReached(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(self::WATCH_PAGE, new FeedUnreachableException('x: HTTP 503', 503));

        self::assertNull((new YouTubeVideoChannelFeed($fetcher))->feedUrl(self::WATCH_PAGE));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php bin/phpunit tests/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeedTest.php`
Expected: an error, because class `YouTubeVideoChannelFeed` is not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Support\YouTubePlaylistLink;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Reader\Media\Support\YouTubeVideoId;

/**
 * Maps a video link to its channel's feed. The watch page advertises no feed, but it names the channel. A video
 * watched inside a playlist is the playlist's link, and is left to YouTubePlaylistFeed.
 */
final readonly class YouTubeVideoChannelFeed implements ShareLinkFeedInterface
{
    private const string SHORT_LINK_HOST = 'youtu.be';

    private const string SHORT_LINK_PATH = '#^/(' . YouTubeVideoId::PATTERN . ')$#';

    private const string VIDEO_PATH = '#^/(?:shorts|live)/(' . YouTubeVideoId::PATTERN . ')/?$#';

    private const string VIDEO_ID = '#^' . YouTubeVideoId::PATTERN . '$#';

    private const string CHANNEL_ID = '#"externalChannelId":"(UC[A-Za-z0-9_-]{22})"#';

    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=%s';

    private const string CHANNEL_FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function feedUrl(string $enteredUrl): ?string
    {
        $videoId = null === YouTubePlaylistLink::listId($enteredUrl) ? $this->videoId($enteredUrl) : null;
        if (null === $videoId) {
            return null;
        }

        $channelId = $this->channelId($videoId);

        return null === $channelId ? null : sprintf(self::CHANNEL_FEED, $channelId);
    }

    private function videoId(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (self::SHORT_LINK_HOST === $host) {
            return 1 === preg_match(self::SHORT_LINK_PATH, $path, $match) ? $match[1] : null;
        }

        if (!YouTubeVideoId::isYouTubeComHost($host)) {
            return null;
        }

        return '/watch' === $path
            ? $this->watchedVideoId($enteredUrl)
            : (1 === preg_match(self::VIDEO_PATH, $path, $match) ? $match[1] : null);
    }

    private function watchedVideoId(string $enteredUrl): ?string
    {
        parse_str((string) parse_url($enteredUrl, PHP_URL_QUERY), $query);
        $videoId = $query['v'] ?? null;

        return \is_string($videoId) && 1 === preg_match(self::VIDEO_ID, $videoId) ? $videoId : null;
    }

    private function channelId(string $videoId): ?string
    {
        try {
            $response = $this->fetcher->fetch(sprintf(self::WATCH_PAGE, $videoId));
        } catch (FetchException) {
            return null;
        }

        return 1 === preg_match(self::CHANNEL_ID, $response->modifiedBody(), $match) ? $match[1] : null;
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: the command from Step 2. Expected: OK.

- [ ] **Step 5: Run lint and commit**

```bash
vendor/bin/phpmd src/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeed.php text phpmd.xml.dist
git add src/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeed.php tests/Service/Discovery/ShareLinkFeed/YouTubeVideoChannelFeedTest.php
git commit -m "feat(#1497): a youtube video link resolves to its channel feed"
```

---

### Task 4: Discovery wiring, end-to-end discovery tests, README

**Files:**
- Modify: `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` (`discovery()`)
- Modify: `backend/tests/Service/Discovery/ShareLinkFeed/ShareLinkFeedsAreConsultedTest.php`
- Create: `backend/tests/Service/Discovery/ShareLinkFeed/PlatformLinkDiscoveryTest.php`
- Modify: `README.md` (the **Watching** and **Feeds** sections)

**Interfaces:**
- Consumes: the three resolvers from Tasks 1–3. No `services.yaml` change: `_instanceof ShareLinkFeedInterface` already tags them `app.share_link_feed`.

- [ ] **Step 1: Write the failing tests**

In `ShareLinkFeedsAreConsultedTest::testTheContainerHandsDiscoveryEveryShareLinkResolver`, add the three use statements and these assertions after the existing two:

```php
        self::assertContains(GitHubRepositoryFeed::class, $classes);
        self::assertContains(YouTubePlaylistFeed::class, $classes);
        self::assertContains(YouTubeVideoChannelFeed::class, $classes);
```

In `BuildsFeedDiscovery::discovery()`, pass all five resolvers, and add the three use statements:

```php
        return $this->discoveryResolving(
            $fetcher,
            [
                new SubstackProfileFeed($fetcher),
                new ApplePodcastShowFeed($fetcher),
                new GitHubRepositoryFeed(),
                new YouTubePlaylistFeed(),
                new YouTubeVideoChannelFeed($fetcher),
            ],
        );
```

Create `PlatformLinkDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Model\ScrapeFallback;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proves discovery subscribes what the GitHub and YouTube resolvers answer; their edge cases are their own tests'. */
final class PlatformLinkDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    public function testARepositoryLinkSubscribesTheReleasesFeedWithoutFetchingThePage(): void
    {
        $feed = 'https://github.com/symfony/symfony/releases.atom';
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());

        $result = $this->discovery($fetcher)
            ->discover('https://github.com/symfony/symfony/tree/7.4', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame([$feed], $fetcher->fetchedUrls);
    }

    public function testAVideoInsideAPlaylistSubscribesThePlaylist(): void
    {
        $feed = 'https://www.youtube.com/feeds/videos.xml?playlist_id=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF';
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());

        $result = $this->discovery($fetcher)->discover(
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            ScrapeFallback::Enabled,
        );

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame([$feed], $fetcher->fetchedUrls);
    }

    public function testAVideoLinkSubscribesItsChannel(): void
    {
        $feed = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA';
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());
        $fetcher->willReturnBody(
            'https://www.youtube.com/watch?v=EeS-cBgIoxI',
            '<script>{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA"}</script>',
        );

        $result = $this->discovery($fetcher)->discover('https://youtu.be/EeS-cBgIoxI', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `php bin/phpunit tests/Service/Discovery`
Expected: all green. The container test proves the tags are real. If it fails on a missing resolver, the class does not implement `ShareLinkFeedInterface`; check that before touching `services.yaml`.

- [ ] **Step 3: Update the README**

In **Watching**, after the channel bullet, add:

```markdown
- A playlist link (`https://www.youtube.com/playlist?list=…`, or a video
  watched inside a playlist) subscribes to the playlist; a video link
  (`https://youtu.be/…`, `…/watch?v=…`, `…/shorts/…`) subscribes to the
  video's channel.
```

In **Feeds**, change the sentence after "finds the feed for you." to:

```markdown
  finds the feed for you. A show link from Apple Podcasts, a SoundCloud
  profile link, a YouTube channel, playlist or video link works too, and a
  GitHub repository link subscribes to the repository's releases.
```

- [ ] **Step 4: Commit**

```bash
git add tests/Service/Discovery README.md
git commit -m "test(#1497): discovery subscribes github and youtube links; readme"
```

---

### Task 5: Gates and the pull request

- [ ] **Step 1: Run the gates (from `backend/`)**

```bash
bin/console cache:warmup
composer check
composer md
composer test:parallel
composer infection:diff
docker compose exec php composer test
```

Expected: `check` and `md` print no findings for the touched files; both suites are green; Infection's MSI is at or above `minMsi`, with no escaped mutant in the four new `src` files. Likely escapes, and the row that kills each:
- `strtolower` on a host: the mixed-case host rows.
- `rtrim`: the trailing-slash playlist row.
- `str_ends_with('.atom')`: the commits-feed row.
- A removed first `RESERVED_OWNERS` item: the `orgs` row.

Add the missing row; never change `infection.json5`.

Also run the PhpStorm inspections (`mcp__phpstorm__lint_files`) on the four new `src` files and the new tests. Block on ERROR and WARNING.

- [ ] **Step 2: Do a live smoke check against the Docker stack (read-only, nothing subscribed)**

Pick whichever of these works:
- In the dev SPA (http://localhost:4200, or the stack's https://localhost:8443), open "Add feed" and paste each of these, then confirm that the preview shows entries. **Cancel**, and do not subscribe.
- Or call the preview API that the dialog calls.

Links to try:
- `https://github.com/symfony/symfony`
- `https://www.youtube.com/playlist?list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF`
- `https://youtu.be/EeS-cBgIoxI`

Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`

- [ ] **Step 3: Push and open the PR into `develop`**

```bash
git push -u origin feature/1497-share-link-feeds
gh pr create --base develop --title "feat(#1497): github repository, youtube playlist and video links subscribe to their feeds" --body "Closes #1497

Three more share-link resolvers: a GitHub repository link → its releases.atom; a YouTube playlist link (also watch?v=…&list=…) → the playlist feed; a YouTube video, Short, live or youtu.be link → its channel feed via the watch page's externalChannelId (one fetch). Auto-generated mixes (list=RD…) have no feed, so they resolve to the channel; GitHub user/org links fall through. Plan: docs/superpowers/plans/2026-10-10-1497-share-link-feeds.md"
```
