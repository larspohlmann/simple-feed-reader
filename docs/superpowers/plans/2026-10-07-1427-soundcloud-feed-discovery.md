# #1427 Discovery: resolve a SoundCloud profile URL to its RSS feed — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** pasting `https://soundcloud.com/<artist>` into "Add feed" offers that account's
`https://feeds.soundcloud.com/users/soundcloud:users:<ID>/sounds.rss` feed as a candidate.

**Architecture:** discovery already fetches the profile page, and that page names its owner in an app deep
link (`<meta property="al:ios:url" content="soundcloud://users:<ID>">`). So the feed is read from the page
body: no host check and no extra request. A new `FeedOfferInterface` ("a feed the page body implies but does not
advertise") covers the existing `WordPressRestProbe` and the new `SoundCloudProfileFeed`. `FeedDiscovery`
iterates the tagged offers where it calls the WordPress probe today. This is the second case of that shape, so it
gets the seam now.

**Tech Stack:** Symfony 7.4 DI (`#[AutowireIterator]`, `_instanceof` tag), PHP 8.4 `\Dom\HTMLDocument`, PHPUnit 12.

**Spec:** [larspohlmann/simple-feed-reader#1427](https://github.com/larspohlmann/simple-feed-reader/issues/1427).
Rulings on its open questions, from the 2026-10-07 probing:

- **Read the page, don't rewrite the URL.** The issue proposed a Substack-style pre-fetch rewrite
  (`SubstackProfileFeed`). That pattern exists because a Substack handle cannot be mapped without an API call. A
  SoundCloud profile page carries the ID itself, and discovery fetches that page anyway. The pre-fetch rewrite
  would cost a second fetch of the same page and a host/path allow-list (`/discover`, `/search`, …) that the
  deep link makes unnecessary.
- **Generalise: yes, as `FeedOfferInterface`.** Its shape is `WordPressRestProbe::offer(string $body,
  string $pageUrl): ?FeedCandidateModel`, so the existing probe becomes the first implementation unchanged.
  `SubstackProfileFeed` stays as it is: it has a different shape (it runs before the fetch, on the URL).
- **Bot protection: none.** `SimpleFeedReader/1.0` gets 200 for `soundcloud.com/<artist>` and
  `m.soundcloud.com/<artist>`, and the feed answers `application/rss+xml`.
- **Track pages exclude themselves.** A track page's deep link is `soundcloud://sounds:<ID>`. `/discover` has no
  `al:ios:url` at all. An unknown user is a 404, so discovery never reaches the offers.
- **Offer, don't verify.** Unlike the WordPress probe, this offer fetches nothing. Every candidate is previewed
  in the dialog, so a wrong guess costs no subscription. A profile whose feed is empty (forss, a16z,
  bbc-world-service) is still offered: its next upload may be included, and the preview shows the user what
  they get. Observed item counts vs tracks: nasa 476/1516, skrillex 24/299, mobitex 9/65, odesza 4/270.
- **Title:** `og:title` ("Mobitex (PCT rec)"). The page `<title>` is the marketing string
  "Stream … music | Listen to songs, albums, playlists for free on SoundCloud".

## Global Constraints

- CLAUDE.md Clean Code rules apply. Every touched `src` file must be PHPMD-clean, PHPStan level max, PSR-12.
- An interface ends in `Interface` and sits in a folder named after it with its same-module implementations
  (`ServiceRoleRule`): `src/Service/Discovery/FeedOffer/`.
- A plain application interface is collected only if it is tagged under `_instanceof` in `config/services.yaml`.
  Without the tag, `#[AutowireIterator]` silently collects nothing.
- No live network in tests. Fixtures are trimmed, hand-made HTML that mirrors the real tags.
- Commits: `type(#1427): lower-case summary`. Branch `feature/1427-soundcloud-feed-discovery` (already created
  off `origin/develop`).
- Comments: default to none. Keep a class docblock to the one non-obvious rule it holds.

## File map

| File | Change |
|---|---|
| `backend/src/Service/Discovery/FeedOffer/FeedOfferInterface.php` | new: the seam |
| `backend/src/Service/Discovery/FeedOffer/WordPressRestProbe.php` | moved from `Discovery/`, implements the seam |
| `backend/src/Service/Discovery/FeedOffer/SoundCloudProfileFeed.php` | new |
| `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php` | iterate offers instead of the WordPress probe |
| `backend/config/services.yaml` | tag `app.feed_offer` |
| `backend/tests/Service/Discovery/FeedOffer/WordPressRestProbeTest.php` | moved, namespace only |
| `backend/tests/Service/Discovery/FeedOffer/SoundCloudProfileFeedTest.php` | new unit test |
| `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` | pass the offers list |
| `backend/tests/Service/Discovery/SoundCloudProfileDiscoveryTest.php` | new: through FeedDiscovery |
| `backend/tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php` | new: proves the container tags both |
| `backend/tests/Fixtures/soundcloud/profile.html`, `track.html` | new fixtures |

---

### Task 1: Extract `FeedOfferInterface`; the WordPress probe becomes its first implementation

A pure refactor: behaviour is unchanged and the existing discovery tests stay green. It is still a reviewable
unit on its own, since it introduces the seam and its DI wiring.

**Files:**
- Create: `backend/src/Service/Discovery/FeedOffer/FeedOfferInterface.php`
- Move: `backend/src/Service/Discovery/WordPressRestProbe.php` → `backend/src/Service/Discovery/FeedOffer/WordPressRestProbe.php`
- Move: `backend/tests/Service/Discovery/WordPressRestProbeTest.php` → `backend/tests/Service/Discovery/FeedOffer/WordPressRestProbeTest.php`
- Modify: `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php`, `backend/config/services.yaml`, `backend/tests/Service/Discovery/BuildsFeedDiscovery.php`
- Create: `backend/tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php`

**Interfaces:**
- Produces: `App\Service\Discovery\FeedOffer\FeedOfferInterface::offer(string $body, string $pageUrl): ?FeedCandidateModel`, tag `app.feed_offer`.
- Produces: `FeedDiscovery::__construct(..., SubstackProfileFeed $substackProfile, iterable<FeedOfferInterface> $offers)` (the last parameter replaces `WordPressRestProbe $wordPressRest`).

- [ ] **Step 1: Write the failing wiring test.** This guards the silent-empty-iterator trap.

`backend/tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Service\Discovery\FeedDiscovery\FeedDiscovery;
use App\Service\Discovery\FeedOffer\FeedOfferInterface;
use App\Service\Discovery\FeedOffer\WordPressRestProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** An untagged interface leaves #[AutowireIterator] empty without an error; this reads what discovery really got. */
final class FeedOffersAreCollectedTest extends KernelTestCase
{
    public function testTheContainerHandsDiscoveryEveryFeedOffer(): void
    {
        $discovery = self::getContainer()->get(FeedDiscovery::class);
        self::assertInstanceOf(FeedDiscovery::class, $discovery);

        $offers = (new \ReflectionProperty($discovery, 'offers'))->getValue($discovery);
        self::assertIsIterable($offers);

        $classes = [];
        foreach ($offers as $offer) {
            self::assertInstanceOf(FeedOfferInterface::class, $offer);
            $classes[] = $offer::class;
        }

        self::assertContains(WordPressRestProbe::class, $classes);
    }
}
```

Reflection is fine in a test, and here it is the only way to prove the container hands discovery a non-empty
iterator. `FeedDiscovery` is a private service; `self::getContainer()` (the test container) can still fetch it.

- [ ] **Step 2: Run it.** `cd backend && php bin/phpunit tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php`
  Expected: FAIL (the `FeedOfferInterface` class does not exist).

- [ ] **Step 3: Create the interface.**

`backend/src/Service/Discovery/FeedOffer/FeedOfferInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Service\Discovery\Model\FeedCandidateModel;

/** A feed a fetched page implies without advertising it; discovery lists every offer after the advertised links. */
interface FeedOfferInterface
{
    public function offer(string $body, string $pageUrl): ?FeedCandidateModel;
}
```

- [ ] **Step 4: Move the probe and its test.**

```bash
cd backend
mkdir -p src/Service/Discovery/FeedOffer tests/Service/Discovery/FeedOffer
git mv src/Service/Discovery/WordPressRestProbe.php src/Service/Discovery/FeedOffer/WordPressRestProbe.php
git mv tests/Service/Discovery/WordPressRestProbeTest.php tests/Service/Discovery/FeedOffer/WordPressRestProbeTest.php
```

In the moved probe, change the namespace to `App\Service\Discovery\FeedOffer` and the class line to
`final readonly class WordPressRestProbe implements FeedOfferInterface`. Nothing else changes. In the moved test,
change the namespace to `App\Tests\Service\Discovery\FeedOffer` and the import to
`App\Service\Discovery\FeedOffer\WordPressRestProbe`.

- [ ] **Step 5: Tag it** in `backend/config/services.yaml`, under `_instanceof`. Put it after the
  `PlatformEntryRuleInterface` entry, before the mailer comment:

```yaml
        App\Service\Discovery\FeedOffer\FeedOfferInterface:
            tags: ['app.feed_offer']
```

- [ ] **Step 6: Iterate the offers in `FeedDiscovery`.**

Imports: drop `use App\Service\Discovery\WordPressRestProbe;` and add
`use App\Service\Discovery\FeedOffer\FeedOfferInterface;` and
`use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;`.

Constructor: replace `private WordPressRestProbe $wordPressRest,` with the iterator and add the param docblock:

```php
    /** @param iterable<FeedOfferInterface> $offers */
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
        private FeedLinkScanner $links,
        private WellKnownFeedProbe $wellKnownFeeds,
        private BotChallengePage $botChallenge,
        private SubstackProfileFeed $substackProfile,
        #[AutowireIterator('app.feed_offer')]
        private iterable $offers,
    ) {
    }
```

In `discover()`, replace the block from the "The page's own advertised feeds lead" comment through the
`$candidates = …;` statement with:

```php
        // The page's own advertised feeds lead, and the dialog opens the first one expanded.
        $candidates = [...$this->links->scan($body, $response->finalUrl), ...$this->offered($body, $response->finalUrl)];
```

Add a private method after `discover()`:

```php
    /** @return list<FeedCandidateModel> */
    private function offered(string $body, string $pageUrl): array
    {
        $candidates = [];
        foreach ($this->offers as $offer) {
            $candidate = $offer->offer($body, $pageUrl);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }
```

The rest of `discover()` (`return [] !== $candidates ? …`) is unchanged. `FeedCandidateModel` is already imported.
If the spread line exceeds the 120-column PSR-12 limit, break it onto one element per line.

- [ ] **Step 7: Update the test builder.** In `backend/tests/Service/Discovery/BuildsFeedDiscovery.php`, change
  the import to `App\Service\Discovery\FeedOffer\WordPressRestProbe` and make the last constructor argument
  `[new WordPressRestProbe($fetcher)],`.

- [ ] **Step 8: Run the discovery tests.** `cd backend && php bin/console cache:clear --env=test && php bin/phpunit tests/Service/Discovery`.
  Expected: all PASS, including `FeedOffersAreCollectedTest` and the two WordPress cases in `FeedDiscoveryTest`
  (lines ~72–115, `wp-json` still the second candidate).

- [ ] **Step 9: Break-test the wiring guard.** Comment out the two new `_instanceof` lines, run
  `php bin/console cache:clear --env=test && php bin/phpunit tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php`,
  and quote the FAIL. Restore the lines by editing them back (not `git checkout --`) and rerun to PASS.

- [ ] **Step 10: Commit.**

```bash
git add -A backend/src/Service/Discovery backend/tests/Service/Discovery backend/config/services.yaml
git commit -m "refactor(#1427): page-implied feeds go through a tagged FeedOfferInterface"
```

---

### Task 2: `SoundCloudProfileFeed` offers a profile's RSS feed

**Files:**
- Create: `backend/src/Service/Discovery/FeedOffer/SoundCloudProfileFeed.php`
- Create: `backend/tests/Service/Discovery/FeedOffer/SoundCloudProfileFeedTest.php`
- Create: `backend/tests/Fixtures/soundcloud/profile.html`, `backend/tests/Fixtures/soundcloud/track.html`
- Modify: `backend/tests/Service/Discovery/BuildsFeedDiscovery.php`, `backend/tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php`
- Create: `backend/tests/Service/Discovery/SoundCloudProfileDiscoveryTest.php`

**Interfaces:**
- Consumes: `FeedOfferInterface` (Task 1), `HtmlDocumentParser::parseOrEmpty(string): HTMLDocument`,
  `TextNormalizer::normalize(string): string`, `FeedCandidateModel(string $url, ?string $title, string $format)`.
- Produces: `App\Service\Discovery\FeedOffer\SoundCloudProfileFeed` (no constructor arguments).

- [ ] **Step 1: Add the fixtures.** These are trimmed copies of the real `<head>` tags (probed 2026-10-07 on
  `soundcloud.com/mobitex` and `soundcloud.com/forss/flickermood`).

`backend/tests/Fixtures/soundcloud/profile.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
<title>Stream Mobitex (PCT rec) music | Listen to songs, albums, playlists for free on SoundCloud</title>
<link rel="alternate" media="only screen and (max-width: 640px)" href="https://m.soundcloud.com/mobitex">
<link rel="alternate" type="text/json+oembed" href="https://soundcloud.com/oembed?url=https%3A%2F%2Fsoundcloud.com%2Fmobitex&amp;format=json">
<link rel="alternate" href="ios-app://336353151/soundcloud/users:19838136">
<meta property="og:type" content="music.musician">
<meta property="og:title" content="Mobitex (PCT rec)">
<meta property="twitter:app:url:iphone" content="soundcloud://users:19838136">
<meta property="al:ios:url" content="soundcloud://users:19838136">
</head>
<body><noscript>JavaScript is disabled</noscript></body>
</html>
```

`backend/tests/Fixtures/soundcloud/track.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
<title>Flickermood by Forss | Free Listening on SoundCloud</title>
<meta property="og:type" content="music.song">
<meta property="og:title" content="Flickermood">
<meta property="al:ios:url" content="soundcloud://sounds:293">
</head>
<body></body>
</html>
```

- [ ] **Step 2: Write the failing unit test.**

`backend/tests/Service/Discovery/FeedOffer/SoundCloudProfileFeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Service\Discovery\FeedOffer\SoundCloudProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SoundCloudProfileFeedTest extends TestCase
{
    private const string PROFILE_URL = 'https://soundcloud.com/mobitex';

    public function testOffersTheRssFeedOfTheUserTheProfileDeepLinks(): void
    {
        $candidate = (new SoundCloudProfileFeed())->offer($this->fixture('profile.html'), self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertSame(
            'https://feeds.soundcloud.com/users/soundcloud:users:19838136/sounds.rss',
            $candidate->url,
        );
        self::assertSame('Mobitex (PCT rec)', $candidate->title);
        self::assertSame('rss', $candidate->format);
    }

    public function testATrackPageOffersNothing(): void
    {
        self::assertNull(
            (new SoundCloudProfileFeed())->offer($this->fixture('track.html'), 'https://soundcloud.com/forss/flickermood'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function pagesWithoutAUserDeepLink(): iterable
    {
        yield 'no deep link' => ['<html><head><title>Discover</title></head></html>'];
        yield 'another app scheme' => ['<html><head><meta property="al:ios:url" content="spotify://users:42"></head></html>'];
        yield 'id with a suffix' => ['<html><head><meta property="al:ios:url" content="soundcloud://users:42/likes"></head></html>'];
        yield 'id with a prefix' => ['<html><head><meta property="al:ios:url" content="xsoundcloud://users:42"></head></html>'];
        yield 'no id' => ['<html><head><meta property="al:ios:url" content="soundcloud://users:"></head></html>'];
        yield 'a non-numeric id' => ['<html><head><meta property="al:ios:url" content="soundcloud://users:abc"></head></html>'];
        yield 'not html' => ['{"kind":"user","id":42}'];
    }

    #[DataProvider('pagesWithoutAUserDeepLink')]
    public function testAPageWithoutAUserDeepLinkOffersNothing(string $body): void
    {
        self::assertNull((new SoundCloudProfileFeed())->offer($body, self::PROFILE_URL));
    }

    public function testAProfileWithoutAnOgTitleIsOfferedUntitled(): void
    {
        $body = '<html><head><title>Stream X music</title>'
            . '<meta property="al:ios:url" content="soundcloud://users:42"></head></html>';

        $candidate = (new SoundCloudProfileFeed())->offer($body, self::PROFILE_URL);

        self::assertSame('https://feeds.soundcloud.com/users/soundcloud:users:42/sounds.rss', $candidate?->url);
        self::assertNull($candidate?->title);
    }

    public function testTheTitleIsWhitespaceNormalised(): void
    {
        $body = '<html><head><meta property="og:title" content="  Mobitex   (PCT rec) ">'
            . '<meta property="al:ios:url" content=" soundcloud://users:42 "></head></html>';

        $candidate = (new SoundCloudProfileFeed())->offer($body, self::PROFILE_URL);

        self::assertSame('Mobitex (PCT rec)', $candidate?->title);
        self::assertSame('https://feeds.soundcloud.com/users/soundcloud:users:42/sounds.rss', $candidate?->url);
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(__DIR__ . '/../../../Fixtures/soundcloud/' . $name);
        self::assertIsString($html);

        return $html;
    }
}
```

The long `yield` lines exceed 120 columns. If `composer cs` flags them, split each array literal across lines.

- [ ] **Step 3: Run it.** `cd backend && php bin/phpunit tests/Service/Discovery/FeedOffer/SoundCloudProfileFeedTest.php`
  Expected: FAIL (the `SoundCloudProfileFeed` class does not exist).

- [ ] **Step 4: Implement.**

`backend/src/Service/Discovery/FeedOffer/SoundCloudProfileFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\HTMLDocument;

/**
 * Offers the RSS feed of the SoundCloud user a page deep-links to. Only a profile names a user there (a track page
 * names `soundcloud://sounds:…`), and the feed is unadvertised and may be empty: the dialog's preview shows which.
 */
final readonly class SoundCloudProfileFeed implements FeedOfferInterface
{
    private const string USER_DEEP_LINK = '#^soundcloud://users:(\d+)$#';

    private const string FEED_URL = 'https://feeds.soundcloud.com/users/soundcloud:users:%s/sounds.rss';

    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        $document = HtmlDocumentParser::parseOrEmpty($body);
        $userId = $this->deepLinkedUserId($document);
        if (null === $userId) {
            return null;
        }

        return new FeedCandidateModel(sprintf(self::FEED_URL, $userId), $this->profileName($document), 'rss');
    }

    private function deepLinkedUserId(HTMLDocument $document): ?string
    {
        $deepLink = trim($this->metaContent($document, 'al:ios:url'));

        return 1 === preg_match(self::USER_DEEP_LINK, $deepLink, $match) ? $match[1] : null;
    }

    private function profileName(HTMLDocument $document): ?string
    {
        $name = TextNormalizer::normalize($this->metaContent($document, 'og:title'));

        return '' === $name ? null : $name;
    }

    private function metaContent(HTMLDocument $document, string $property): string
    {
        return $document->querySelector(sprintf('meta[property="%s"]', $property))?->getAttribute('content') ?? '';
    }
}
```

`$pageUrl` is unused here; the interface carries it for the WordPress probe. PHPMD runs only the codesize
ruleset, so no unused-parameter rule fires. `TextNormalizer::normalize` collapses and trims whitespace
(`Whitespace::collapse`), which the whitespace test pins.

- [ ] **Step 5: Run the unit test.** Same command as Step 3. Expected: PASS.

- [ ] **Step 6: Wire it into the test builder and the collection test.** In `BuildsFeedDiscovery.php`, add the
  import `App\Service\Discovery\FeedOffer\SoundCloudProfileFeed` and make the last argument
  `[new WordPressRestProbe($fetcher), new SoundCloudProfileFeed()],`. In `FeedOffersAreCollectedTest`, add the
  import and `self::assertContains(SoundCloudProfileFeed::class, $classes);`.

- [ ] **Step 7: Write the discovery-level test.**

`backend/tests/Service/Discovery/SoundCloudProfileDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\Model\ScrapeFallback;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proves FeedDiscovery lists the profile's feed; the deep-link edge cases are SoundCloudProfileFeedTest's. */
final class SoundCloudProfileDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    private const string PROFILE_URL = 'https://soundcloud.com/mobitex';

    public function testAProfileOffersItsRssFeedBeforeAnyGuessOrScrape(): void
    {
        $fetcher = $this->fetcherReturning(self::PROFILE_URL, self::PROFILE_URL, $this->fixture('profile.html'));

        $result = $this->discovery($fetcher)->discover(self::PROFILE_URL, ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertCount(1, $result->candidates);
        self::assertSame(
            'https://feeds.soundcloud.com/users/soundcloud:users:19838136/sounds.rss',
            $result->candidates[0]->url,
        );
        self::assertSame('rss', $result->candidates[0]->format);
        self::assertSame([self::PROFILE_URL], $fetcher->fetchedUrls);
    }

    public function testATrackPageIsLeftToTheUsualFallbacks(): void
    {
        $trackUrl = 'https://soundcloud.com/forss/flickermood';
        $fetcher = $this->fetcherReturning($trackUrl, $trackUrl, $this->fixture('track.html'));

        $result = $this->discovery($fetcher)->discover($trackUrl, ScrapeFallback::Disabled);

        self::assertSame([], $result->candidates);
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(__DIR__ . '/../../Fixtures/soundcloud/' . $name);
        self::assertIsString($html);

        return $html;
    }
}
```

The `fetchedUrls` assertion pins "no extra request": no well-known probe ran and the offer fetched nothing.
`StubFeedFetcher::$fetchedUrls` is public (used the same way in `WordPressRestProbeTest`). If the
`ScrapeFallback::Disabled` case name differs, read `src/Service/Discovery/Model/ScrapeFallback.php`. In the track
case, discovery probes the well-known paths, the stub 404s them all, and the fallback is off, so the result is
`candidates([])`.

- [ ] **Step 8: Run the discovery tests.** `cd backend && php bin/console cache:clear --env=test && php bin/phpunit tests/Service/Discovery`.
  Expected: all PASS.

- [ ] **Step 9: Break-test.** In `SoundCloudProfileFeed::USER_DEEP_LINK`, change `users` to `sounds`. Run
  Step 8, quote the FAILs (the unit test plus `testAProfileOffersItsRssFeedBeforeAnyGuessOrScrape`), restore by
  editing, and rerun to PASS.

- [ ] **Step 10: Commit.**

```bash
git add backend/src/Service/Discovery/FeedOffer/SoundCloudProfileFeed.php backend/tests/Fixtures/soundcloud backend/tests/Service/Discovery
git commit -m "feat(#1427): discovery offers a soundcloud profile's rss feed"
```

---

### Task 3: Gates, a real run, PR

- [ ] **Step 1: Backend gates** (from `backend/`): `composer cs`, `bin/console cache:warmup && composer stan`,
  `composer md` (every touched `src` file clean), `composer tramp`, `composer test:parallel`, and the MySQL leg
  `docker compose exec php composer test`. Then `composer infection:diff`. Commit first: `infection:diff` ignores
  untracked files.
- [ ] **Step 2: PhpStorm inspections** on the changed PHP (`mcp__phpstorm__lint_files`): block on ERROR/WARNING.
- [ ] **Step 3: Real run.** Check the Docker `php` container serves this checkout's code (memory: verify
  containers are current; clear the DI cache). Then call discovery on the real page via the API the subscribe
  dialog uses, for `https://soundcloud.com/mobitex`. Expect one `rss` candidate for `soundcloud:users:19838136`
  whose preview lists the 9 tracks. Also run `https://soundcloud.com/forss` (empty feed: the candidate is still
  offered and the preview is empty), then scan today's dev log
  (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`).
- [ ] **Step 4: Push and open the PR** against `develop`, with the body `Closes #1427` plus the rulings from this
  plan's Spec section (read-the-page over pre-fetch rewrite, the `FeedOfferInterface` seam, empty feeds still
  offered, observed item counts).
