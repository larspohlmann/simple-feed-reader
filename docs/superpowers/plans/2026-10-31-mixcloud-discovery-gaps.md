# Mixcloud Discovery Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pasting a Mixcloud show page or API address offers the user's cloudcasts feed, and a Mixcloud feed shows the user's picture as its logo (#1935).

**Architecture:** `MixcloudProfileFeed` (a `FeedOfferInterface`) already offers the cloudcasts feed for a profile page, deciding from the URL. It learns two more URL shapes through new `MixcloudUrl` readers: a show page and an `api.mixcloud.com` user or cloudcasts address. Discovery already falls through to the offers when the fetched body is no RSS/Atom, which a JSON API answer never is, so `FeedDiscovery` is not touched. `MixcloudJsonParser` fills `ParsedFeedModel::$imageUrl` from the first show's `user.pictures`, which `EntryIngestor` already stores as the feed's image.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit 12.

**Spec:** none written; the design was approved in chat on 2026-10-31 and is recorded in issue #1935 and in this header. Rulings are provisional against it.

## Global Constraints

- Branch `feature/1935-mixcloud-discovery-gaps`, cut from `feature/1934-opml-verbatim-formats` (a stack: #1932 → #1934 → #1935; both later branches touch `MixcloudUrl`); commits `type(#1935): …`, no Claude attribution.
- The offered URL is always `https://api.mixcloud.com/<user>/cloudcasts/`, format `SourceFormat::MIXCLOUD_API`.
- A show-page or API offer carries a null title (a show page's `<title>` names the show, not the user); the profile-page offer keeps its page title.
- A site section (`MixcloudUrl::SITE_SECTIONS`) is never a user, on either host.
- The feed logo is `user.pictures.large` (300×300) of the first show in the list; none when absent.
- Legacy `www.mixcloud.com/widget` embeds stay out of scope.
- Gates: `composer check`, `composer md`, both PHPUnit legs, `composer infection:diff`, PhpStorm lint on changed PHP (block on ERROR/WARNING). CLAUDE.md Clean Code rules apply; no new comments unless a reader would get the code wrong without one.

---

### Task 1: Read the user of a show page and of an API address

**Files:**
- Modify: `backend/src/Service/Url/Support/MixcloudUrl.php`
- Test: `backend/tests/Service/Url/Support/MixcloudUrlTest.php`

**Interfaces:**
- Produces: `MixcloudUrl::showUser(string $url): ?string` (the first segment of `showKey()`), `MixcloudUrl::apiUser(string $url): ?string` (host `api.mixcloud.com`, any case; path `/<SEGMENT>`, `/<SEGMENT>/` or `/<SEGMENT>/cloudcasts` with an optional trailing slash; a site section is null).

- [ ] **Step 1: Write the failing tests** — add to `MixcloudUrlTest`:

```php
    /** @return iterable<string, array{string, ?string}> */
    public static function showPageUsers(): iterable
    {
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', 'johndigweed'];
        yield 'an encoded user' => ['https://www.mixcloud.com/worldwidefm/caf%C3%A9-show/', 'worldwidefm'];
        yield 'a profile page' => ['https://www.mixcloud.com/johndigweed/', null];
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/favorites/', null];
        yield 'a site section' => ['https://www.mixcloud.com/discover/house/', null];
        yield 'the API host' => ['https://api.mixcloud.com/johndigweed/transitions-1154/', null];
    }

    #[DataProvider('showPageUsers')]
    public function testReadsTheUserOfAShowPage(string $url, ?string $user): void
    {
        self::assertSame($user, MixcloudUrl::showUser($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function apiUsers(): iterable
    {
        yield 'a cloudcasts list' => ['https://api.mixcloud.com/johndigweed/cloudcasts/', 'johndigweed'];
        yield 'a cloudcasts list without the slash' => ['https://api.mixcloud.com/johndigweed/cloudcasts', 'johndigweed'];
        yield 'a user' => ['https://api.mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'a user without the slash' => ['https://api.mixcloud.com/johndigweed', 'johndigweed'];
        yield 'with a query' => ['https://api.mixcloud.com/johndigweed/cloudcasts/?limit=5', 'johndigweed'];
        yield 'a mixed-case host' => ['https://API.Mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'another list' => ['https://api.mixcloud.com/johndigweed/favorites/', null];
        yield 'a show' => ['https://api.mixcloud.com/johndigweed/transitions-1154/', null];
        yield 'a site section' => ['https://api.mixcloud.com/discover/', null];
        yield 'the API root' => ['https://api.mixcloud.com/', null];
        yield 'the site host' => ['https://www.mixcloud.com/johndigweed/', null];
        yield 'a look-alike host' => ['https://api.mixcloud.com.evil.test/johndigweed/', null];
    }

    #[DataProvider('apiUsers')]
    public function testReadsTheUserOfAnApiAddress(string $url, ?string $user): void
    {
        self::assertSame($user, MixcloudUrl::apiUser($url));
    }
```

- [ ] **Step 2: Run to see them fail** — `php bin/phpunit --filter MixcloudUrlTest` → errors: undefined methods `showUser`, `apiUser`.

- [ ] **Step 3: Implement** — `MixcloudUrl` already has `API_HOST`, `cloudcastsUrl()` and `isCloudcastsUrl()` from #1934. `apiUser` would be the third copy of "parse the URL, check the host, take the path" (after `pagePath` and `isCloudcastsUrl`), so extract it first and route all three through it:

```php
    /** @param list<string> $hosts lower-case */
    private static function pathOn(string $url, array $hosts): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\in_array(strtolower($parts['host'] ?? ''), $hosts, true)) {
            return null;
        }

        return $parts['path'] ?? '/';
    }
```

`pagePath($url)` becomes `self::pathOn($url, self::PAGE_HOSTS)` (inline it at its callers and delete `pagePath` if that reads better), `isCloudcastsUrl` becomes `preg_match('#^/' . self::SEGMENT . '/cloudcasts/?\z#', self::pathOn($url, [self::API_HOST]) ?? '') === 1`, and the new methods:

```php
    public static function showUser(string $url): ?string
    {
        $key = self::showKey($url);

        return $key === null ? null : explode('/', $key)[1];
    }

    public static function apiUser(string $url): ?string
    {
        $path = self::pathOn($url, [self::API_HOST]);
        if ($path === null || preg_match('#^/(' . self::SEGMENT . ')(?:/(?:cloudcasts/?)?)?\z#', $path, $matches) !== 1) {
            return null;
        }

        return self::isSiteSection($matches[1]) ? null : $matches[1];
    }
```

The existing `profileUser`, `showKey` and `isCloudcastsUrl` tests must stay green unchanged.

- [ ] **Step 4: Run** — `php bin/phpunit --filter MixcloudUrlTest` → all pass.

- [ ] **Step 5: Commit** — `feat(#1935): read the Mixcloud user of a show page and an API address`.

### Task 2: Offer the cloudcasts feed for a show page and an API address

**Files:**
- Modify: `backend/src/Service/Discovery/FeedOffer/MixcloudProfileFeed.php`
- Test: `backend/tests/Service/Discovery/FeedOffer/MixcloudProfileFeedTest.php`

**Interfaces:**
- Consumes: Task 1's `showUser`, `apiUser`; existing `profileUser`.

- [ ] **Step 1: Write the failing tests** — in `MixcloudProfileFeedTest`, move `'a show page'` out of `pagesThatAreNoProfile` (it is now offered), rename that provider `pagesThatOfferNothing` and its test `testOffersNothingForAPageThatNamesNoUser`, add `yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/favorites/'];` to it, and add:

```php
    /** @return iterable<string, array{string}> */
    public static function addressesNamingTheUser(): iterable
    {
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'the cloudcasts API' => ['https://api.mixcloud.com/johndigweed/cloudcasts/'];
        yield 'the user API' => ['https://api.mixcloud.com/johndigweed/'];
    }

    #[DataProvider('addressesNamingTheUser')]
    public function testOffersTheUsersCloudcastsUntitledForAShowPageOrAnApiAddress(string $pageUrl): void
    {
        $candidate = (new MixcloudProfileFeed())->offer(self::PROFILE_PAGE, $pageUrl);

        self::assertNotNull($candidate);
        self::assertSame('https://api.mixcloud.com/johndigweed/cloudcasts/', $candidate->url);
        self::assertNull($candidate->title);
        self::assertSame(SourceFormat::MIXCLOUD_API, $candidate->format);
    }
```

(`PROFILE_PAGE` is passed as the body on purpose: a title in the body must not reach a show or API offer.)

- [ ] **Step 2: Run to see it fail** — `php bin/phpunit --filter MixcloudProfileFeedTest` → the three new cases fail (null candidate).

- [ ] **Step 3: Implement** — `offer()` becomes:

```php
    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        $profileUser = MixcloudUrl::profileUser($pageUrl);
        if ($profileUser !== null) {
            return $this->cloudcastsOf($profileUser, $this->pageTitle(HtmlDocumentParser::parseOrEmpty($body)));
        }
        $user = MixcloudUrl::showUser($pageUrl) ?? MixcloudUrl::apiUser($pageUrl);

        return $user === null ? null : $this->cloudcastsOf($user, null);
    }

    private function cloudcastsOf(string $user, ?string $title): FeedCandidateModel
    {
        return new FeedCandidateModel(MixcloudUrl::cloudcastsUrl($user), $title, SourceFormat::MIXCLOUD_API);
    }
```

and the class docblock: `/** Offers the shows of the Mixcloud user a profile, show or API address names; Mixcloud publishes no RSS, only this API. */`

- [ ] **Step 4: Run** — `php bin/phpunit --filter 'MixcloudProfileFeedTest|FeedDiscovery'` → all pass.

- [ ] **Step 5: Commit** — `feat(#1935): offer a Mixcloud user's shows from a show page or API address`.

### Task 3: The user's picture is the feed logo

**Files:**
- Modify: `backend/src/Service/Parser/MixcloudJsonParser.php` (`parse`)
- Modify: `backend/tests/Fixtures/mixcloud/cloudcasts.json` (first show's `user` gains `pictures`, as the live API sends it)
- Test: `backend/tests/Service/Parser/MixcloudJsonParserTest.php`

- [ ] **Step 1: Record the picture in the fixture** — in the first show's `user` object, after `"username": "johndigweed"`, add (values from the live API on 2026-10-31):

```json
        "pictures": {
          "medium": "https://thumbnailer.mixcloud.com/unsafe/100x100/profile/2/d/9/f/2595-e2a4-4c6b-aa0e-e7420a523bd1",
          "large": "https://thumbnailer.mixcloud.com/unsafe/300x300/profile/2/d/9/f/2595-e2a4-4c6b-aa0e-e7420a523bd1",
          "extra_large": "https://thumbnailer.mixcloud.com/unsafe/600x600/profile/2/d/9/f/2595-e2a4-4c6b-aa0e-e7420a523bd1"
        }
```

- [ ] **Step 2: Write the failing tests** — in `testTheFeedIsNamedAndLinkedAfterTheUser` replace `self::assertNull($feed->imageUrl);` with

```php
        self::assertSame(
            'https://thumbnailer.mixcloud.com/unsafe/300x300/profile/2/d/9/f/2595-e2a4-4c6b-aa0e-e7420a523bd1',
            $feed->imageUrl,
        );
```

and rename it `testTheFeedIsNamedLinkedAndPicturedAfterTheUser`. Add:

```php
    public function testAUserWithoutAPictureLeavesTheFeedWithoutALogo(): void
    {
        $feed = $this->parse('{"data":[{"key":"/a/b/","url":"https://www.mixcloud.com/a/b/","name":"B",'
            . '"user":{"name":"A","url":"https://www.mixcloud.com/a/","pictures":{"medium":"https://x.test/m"}}}]}');

        self::assertNull($feed->imageUrl);
    }
```

(Check `testAnEmptyListIsAnEmptyUntitledFeed` already asserts a null `imageUrl` for `{"data":[]}`; if not, add the assertion there.)

- [ ] **Step 3: Run to see it fail** — `php bin/phpunit --filter MixcloudJsonParserTest` → the renamed test fails (null vs the 300×300 URL).

- [ ] **Step 4: Implement** — in `parse()`, the fourth constructor argument `null` becomes `$this->text($this->record($owner, 'pictures'), 'large')`.

- [ ] **Step 5: Run** — `php bin/phpunit --filter 'MixcloudJsonParserTest|MixcloudBodyParser|FeedPreview|Subscription'` → all pass (other tests read the same fixture).

- [ ] **Step 6: Commit** — `feat(#1935): show a Mixcloud user's picture as the feed logo`.

### Task 4: Gates and live check

- [ ] `composer check`, `composer md`, `php bin/phpunit` and `docker compose exec php composer test:parallel` (in parallel), `composer infection:diff` (kill any escaped mutant with a test), PhpStorm lint on the changed PHP, scan today's `backend/var/log/dev-*.log`.
- [ ] Live (controller, :4200): paste `https://www.mixcloud.com/johndigweed/<a current show>/` and `https://api.mixcloud.com/nts/cloudcasts/` into Add feed; each offers the Mixcloud candidate; after a refresh the feed shows the user's picture.
