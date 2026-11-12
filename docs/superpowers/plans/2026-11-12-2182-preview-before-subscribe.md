# Preview before subscribing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every client can show the feeds found at an address, each with a preview, before anything is subscribed: `POST /api/feeds/discover` finds and previews, `POST /api/subscriptions` only subscribes what was picked (#2182).

**Architecture:** A new `App\Service\Preview\PreviewedDiscovery` runs the existing `FeedDiscoveryInterface` and never subscribes. A direct feed comes back as one candidate whose preview is built from the document discovery already parsed, by a `FeedPreviewFactory` extracted from `FeedPreviewService` (no second fetch). Account-only feeds (a Bluesky profile hidden from logged-out readers) reach discover through `Discovery\AccountOnlyFeed\AccountOnlyFeedInterface`, owned by the low `Discovery` module and implemented in `Social`, tagged and iterated like `app.subscribe_route`. `SubscriptionService::subscribe()` keeps its verbatim and routed paths and still runs discovery for a feed URL (it stores the parsed document as the first fetch), but answers anything that is not a feed with a typed `NotAFeedException` → 422 `not_a_feed`. The SPA's Add runs discover; each card's Subscribe posts the picked candidate.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 12, Angular 20 (standalone, signals), Jest 30, Playwright, Transloco.

**Spec:** GitHub issue #2182 (body + decision comment)

## Decisions this plan makes, and why

1. **The discover service lives in `Service/Preview`.** It needs `Discovery` (discovery) and the preview builder. `Subscription` cannot host it: `Preview → Refresh → Subscription` already exists, so `Subscription → Preview` would close a cycle (`ServiceModuleCycleRule`). `Discovery` cannot either (`Preview → Discovery`). `Preview` already depends on `Discovery`, so it adds no edge.
2. **The hidden-profile seam is owned by `Discovery`.** `Social` reaches `Discovery` and `Preview` reaches `Social` (through `Refresh`), so an interface owned by `Preview` and implemented in `Social` would close `Social → Preview → … → Social`. `Discovery` reaches nothing that reaches `Social`. The new tag is `app.account_only_feed`.
3. **The account-only check runs in `PreviewedDiscovery`, not inside `FeedDiscovery`.** `FeedDiscovery` stays untouched (and so does its test builder), and the Bluesky label lookup runs only for discover, never inside subscribe.
4. **A direct feed is labelled `rss` or `atom`, as the issue says.** The parsed document does not know its dialect today, so `ParsedFeedModel` gains a nullable `FeedSyntax` (set by the RSS 1.0/2.0 and Atom parsers, null for JSON/scraped/social documents). `FeedCandidateModel::of(DiscoveredFeedModel)` falls back to the existing guessed format `'feed'`, whose constant moves from `FeedLinkScanner` to `FeedCandidateModel` so both share it.
5. **The inline preview is the preview endpoint's own shape.** `FeedPreviewJson::preview()` is the inner object of `POST /api/feeds/preview`'s `feed`, so the SPA reuses `FeedPreview` unchanged. `preview` and `noPreviewReason` are left out when absent, as `scrapeFailureReason` already is.
6. **The hidden profile candidate** is `{url: <the URL as entered>, title: <profile name>, format: 'bluesky-api', noPreviewReason: …}`. `bluesky-api` is not a verbatim format, so the card's Subscribe posts the URL without a format, and `HiddenProfileSubscribeRoute` takes it exactly as today. **Two reasons, so the card says up front what Subscribe will need (Lars):** `hidden_profile` when the user has a ready connected Bluesky account that can read it, `connect_bluesky_account` when they have none (or it needs a reconnect). The second one is the case where Subscribe answers `422 bluesky_profile_hidden`. To tell them apart, `AccountOnlyFeedInterface::candidateFor(User $user, string $url)` takes the user, and the Social implementation asks `SocialAccountRepository::findReadyActingOnNetwork()`, the same check the subscribe route makes.
7. **`SubscribeOutcomeModel` loses its candidate half.** Subscribe never answers with candidates, so `$subscription` becomes non-null, `candidates()` and `scrapeFailureReason` go, and `SubscribeOutcomeJson` (only used for the candidate answer) is deleted with its test.
8. **Subscribe asks discovery with `ScrapeFallback::Disabled`.** A scraped candidate is never subscribed by this path any more (scraped subscribes are verbatim), so running the extractor only to throw `NotAFeedException` would be wasted work.
9. **Discover has its own limiter `feed_discover`** (sliding window, 60 per 5 minutes, per user), the same numbers as `feed_preview` ("rate-limited like preview") but a separate budget, so previewing cards never starves a new search.
10. **Order of tasks keeps every commit working:** the backend gains discover (Tasks 1–3), the SPA switches to it (Tasks 4–5), and only then does subscribe stop answering with candidates (Task 6).
11. **A direct feed is fetched twice, and that is accepted (Lars).** Discover reads it and previews it from that read. The card's Subscribe then runs discovery again and fetches it a second time, because subscribe stores the document it reads as the feed's first fetch. A site that rations requests may answer the second fetch with 429 (#290). Discovery reports that as `Throttled`, and subscribe answers `422 not_a_feed`. There is no document cache and no follow-up issue.

## Global Constraints

- Every rule in `CLAUDE.md` binds: Clean Code names, `final readonly` services, one role per folder (`docs/architecture.md` §10), `ServiceModuleCycleRule`/`ServiceModuleBoundaryRule`, Domain code knows no HTTP (no `App\Http`/`App\Dto` in `App\Service`), queries only in `src/Repository` (this plan adds none), typed exceptions next to their service mapped in `src/Http/Problem/ExceptionProblems/*Problems`.
- **No comments** unless a future reader would get the code wrong without one (CLAUDE.md's bar). Moved code keeps only the comments it already had that still clear that bar.
- **`Response::HTTP_*` constants, never numeric statuses**, in `src` and in every test line this plan writes or changes.
- No `@phpstan-ignore`, no baselines, no edits under `backend/tests/PhpStan`, no PHPMD suppressions. **Every touched `src` file is PHPMD-clean before its commit** (`composer md`).
- Native-iOS checklist (`docs/architecture.md` §6) for `POST /api/feeds/discover`: bearer auth (the `/api` firewall), stateless, JSON in, JSON out and `application/problem+json` errors, no browser-only input, no redirect handoff, no user-facing link. Every box is checked by this design; keep it that way.
- Commit format `type(#2182): …`. No Claude attribution. Do not push. Do not switch branches; the branch is `fix/2182-preview-before-subscribe`. Concurrent sessions may share the checkout: never `checkout`, `reset` or `stash`.

### Commands

```bash
cd backend && php bin/phpunit <path>                        # one file, SQLite
cd backend && composer test:parallel                        # full SQLite leg
docker compose exec php composer test:parallel              # full MySQL leg (repo root); runs alongside the SQLite leg
cd backend && composer check && composer md                 # cs + stan + tramp, then PHPMD (stan needs: bin/console cache:warmup)
cd backend && composer infection:diff                       # mutation gate over this branch's files (needs pcov)
docker compose exec -T frontend npx jest <path>             # Jest, always inside the frontend container
docker compose exec -T frontend npm run check               # the frontend CI gate, inside the container
cd frontend && npm run e2e -- <spec files>                  # Playwright; needs the Docker stack up from this checkout
docker compose exec php bin/console cache:clear && docker compose restart worker   # after DI/config changes, before any e2e
```

After backend work, scan today's dev log for deprecations: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`

---

## File Structure

| Path | Responsibility | Task |
|---|---|---|
| `backend/src/Service/Parser/Model/FeedSyntax.php` (new) | enum `Rss`/`Atom`: the dialect a feed document was read as | 1 |
| `backend/src/Service/Parser/Model/ParsedFeedModel.php` | + `?FeedSyntax $syntax = null` | 1 |
| `backend/src/Service/Parser/FeedFormatParser/{Rss1Parser,Rss2Parser,AbstractAtomParser}.php` | set the syntax | 1 |
| `backend/src/Service/Discovery/Model/FeedCandidateModel.php` | + `GUESSED_FORMAT`, `of(DiscoveredFeedModel)`; Task 3: + `?NoPreviewReason` | 1, 3 |
| `backend/src/Service/Discovery/FeedLinkScanner.php` | uses `FeedCandidateModel::GUESSED_FORMAT` | 1 |
| `backend/src/Service/Preview/Factory/FeedPreviewFactory.php` (new) | `fromDocument(ParsedFeedModel): FeedPreviewModel`, the summary logic moved out of `FeedPreviewService` | 1 |
| `backend/src/Service/Preview/FeedPreviewService.php` | fetch + parse, then delegates to the factory | 1 |
| `backend/src/Service/Url/Support/EnteredUrl.php` (new) | `withScheme()`: the bare-host normalisation, moved from `SubscribeRequest` | 2 |
| `backend/src/Dto/Subscription/SubscribeRequest.php` | uses `EnteredUrl` | 2 |
| `backend/src/Dto/Feed/DiscoverFeedsRequest.php` (new) | the discover body `{url}` | 2 |
| `backend/src/Service/Preview/Model/{PreviewedCandidateModel,PreviewedDiscoveryModel}.php` (new) | a candidate with its optional preview; the list plus scrape failure reason | 2 |
| `backend/src/Service/Preview/PreviewedDiscovery.php` (new) | the discover service | 2, 3 |
| `backend/src/Http/FeedPreviewJson.php` | + public `preview()` (the inner `feed` object) | 2 |
| `backend/src/Http/PreviewedDiscoveryJson.php` (new) | the discover response | 2, 3 |
| `backend/src/Controller/Api/FeedPreviewController.php` | + `POST /api/feeds/discover` | 2 |
| `backend/config/packages/rate_limiter.yaml` | + `feed_discover` | 2 |
| `backend/src/Service/Discovery/Model/NoPreviewReason.php` (new) | enum `HiddenProfile = 'hidden_profile'`, `ConnectBlueskyAccount = 'connect_bluesky_account'` | 3 |
| `backend/src/Service/Discovery/AccountOnlyFeed/AccountOnlyFeedInterface.php` (new) | the seam Social implements | 3 |
| `backend/config/services.yaml` | tag `app.account_only_feed` | 3 |
| `backend/src/Service/Social/TolerantHiddenProfiles.php` | + `hiddenAt(url, warning)` shared by route and discover | 3 |
| `backend/src/Service/Social/HiddenProfileSubscribeRoute.php` | uses `hiddenAt()` | 3 |
| `backend/src/Service/Social/HiddenProfileAccountOnlyFeed.php` (new) | offers a hidden profile as a no-preview candidate | 3 |
| `frontend/src/app/reader/models.ts`, `reader-api.ts` | `DiscoverResult`, `NoPreviewReason`, candidate `preview`/`noPreviewReason`, `discoverFeeds()` | 4 |
| `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.{ts,html,spec.ts}` | Add discovers; cards subscribe | 4 |
| `frontend/public/i18n/{en,de}.json` | `dialog.addFeed.noPreviewHiddenProfile`, `noPreviewConnectBluesky`, `noPreviewGeneric` | 4 |
| `docs/features.md` | preview line | 4 |
| `frontend/e2e/{feed-directory,add-feed-mobile}.spec.ts` | stub discover | 5 |
| `backend/src/Service/Subscription/Exception/NotAFeedException.php` (new) | subscribe found no feed | 6 |
| `backend/src/Http/Problem/ExceptionProblems/SubscriptionProblems.php` | `not_a_feed` → 422 | 6 |
| `backend/src/Service/Subscription/{SubscriptionService,Model/SubscribeOutcomeModel}.php`, `src/Controller/Api/SubscriptionController.php` | subscribe only subscribes | 6 |
| `backend/src/Http/SubscribeOutcomeJson.php`, `backend/tests/Http/SubscribeOutcomeJsonTest.php` | deleted | 6 |
| `docs/architecture.md` §6 | "Adding a feed" contract for native clients | 6 |

---

### Task 1: A parsed feed knows its dialect and previews without a fetch

**Files:**
- Create: `backend/src/Service/Parser/Model/FeedSyntax.php`
- Modify: `backend/src/Service/Parser/Model/ParsedFeedModel.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php:51-57`, `Rss2Parser.php:60-67`, `AbstractAtomParser.php:81-87`
- Modify: `backend/src/Service/Discovery/Model/FeedCandidateModel.php`
- Modify: `backend/src/Service/Discovery/FeedLinkScanner.php:34-38,99`
- Create: `backend/src/Service/Preview/Factory/FeedPreviewFactory.php`
- Modify: `backend/src/Service/Preview/FeedPreviewService.php`
- Test: `backend/tests/Service/Parser/FeedParserTest.php` (add), `backend/tests/Service/Discovery/Model/FeedCandidateModelTest.php` (create), `backend/tests/Service/Preview/Factory/FeedPreviewFactoryTest.php` (create), `backend/tests/Service/Preview/FeedPreviewServiceTest.php:32-41` (constructor)

**Interfaces:**
- Produces: `enum App\Service\Parser\Model\FeedSyntax: string { case Rss = 'rss'; case Atom = 'atom'; }`; `ParsedFeedModel::$syntax: ?FeedSyntax` (last constructor parameter, default null); `FeedCandidateModel::GUESSED_FORMAT = 'feed'`; `FeedCandidateModel::of(DiscoveredFeedModel $feed): FeedCandidateModel`; `App\Service\Preview\Factory\FeedPreviewFactory::fromDocument(ParsedFeedModel $feed): FeedPreviewModel`; `FeedPreviewService::__construct(FeedFetcherInterface, FeedBodyParser, ScrapeFallbackPolicy, FeedPreviewFactory)`.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Service/Parser/FeedParserTest.php` (imports `App\Service\Parser\Model\FeedSyntax` and `PHPUnit\Framework\Attributes\DataProvider`):

```php
    /** @return iterable<string, array{string, FeedSyntax}> */
    public static function dialects(): iterable
    {
        yield 'RSS 2.0' => ['feeds/rss2-basic.xml', FeedSyntax::Rss];
        yield 'RSS 1.0' => ['feeds/rss1-basic.xml', FeedSyntax::Rss];
        yield 'Atom 1.0' => ['feeds/atom-basic.xml', FeedSyntax::Atom];
        yield 'Atom 0.3' => ['feeds/atom-03-basic.xml', FeedSyntax::Atom];
    }

    #[DataProvider('dialects')]
    public function testADocumentNamesTheDialectItWasReadAs(string $fixture, FeedSyntax $syntax): void
    {
        self::assertSame($syntax, FeedFormatParsers::feed($this->fixture($fixture))->syntax);
    }
```

Create `backend/tests/Service/Discovery/Model/FeedCandidateModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\Model;

use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Parser\Model\FeedSyntax;
use App\Service\Parser\Model\ParsedFeedModel;
use PHPUnit\Framework\TestCase;

final class FeedCandidateModelTest extends TestCase
{
    public function testADiscoveredFeedIsOfferedUnderItsFinalUrlTitleAndDialect(): void
    {
        $feed = new DiscoveredFeedModel(
            'https://example.com/feed.xml',
            new ParsedFeedModel('Example', null, null, null, [], syntax: FeedSyntax::Atom),
        );

        self::assertEquals(
            new FeedCandidateModel('https://example.com/feed.xml', 'Example', 'atom'),
            FeedCandidateModel::of($feed),
        );
    }

    public function testADocumentOfUnknownDialectIsOfferedAsAGuessedFeed(): void
    {
        $feed = new DiscoveredFeedModel('https://example.com/feed', new ParsedFeedModel(null, null, null, null, []));

        self::assertSame(FeedCandidateModel::GUESSED_FORMAT, FeedCandidateModel::of($feed)->format);
        self::assertNull(FeedCandidateModel::of($feed)->title);
    }
}
```

Create `backend/tests/Service/Preview/Factory/FeedPreviewFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview\Factory;

use App\Entity\Headline;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Preview\Factory\FeedPreviewFactory;
use PHPUnit\Framework\TestCase;

final class FeedPreviewFactoryTest extends TestCase
{
    public function testSummarizesADocumentItIsHanded(): void
    {
        $entry = new ParsedEntryModel(
            guid: 'https://example.com/1',
            url: 'https://example.com/1',
            headline: Headline::fromFeed('First post'),
            author: 'A. Writer',
            summary: 'A short teaser.',
            contentHtml: null,
            publishedAt: new \DateTimeImmutable('2026-07-01T10:00:00Z'),
        );

        $preview = new FeedPreviewFactory()->fromDocument(
            new ParsedFeedModel('Example Feed', null, null, null, [$entry]),
        );

        self::assertSame('Example Feed', $preview->title);
        self::assertSame(1, $preview->itemCount);
        self::assertSame('summary', $preview->content);
        self::assertFalse($preview->hasImages);
        self::assertCount(1, $preview->items);
        self::assertSame('First post', $preview->items[0]->title);
        self::assertSame('A. Writer', $preview->items[0]->author);
        self::assertSame('A short teaser.', $preview->items[0]->summary);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Parser/FeedParserTest.php tests/Service/Discovery/Model/FeedCandidateModelTest.php tests/Service/Preview/Factory/FeedPreviewFactoryTest.php`
Expected: FAIL — `Class "App\Service\Parser\Model\FeedSyntax" not found`, `Class "App\Service\Preview\Factory\FeedPreviewFactory" not found`.

- [ ] **Step 3: Add the dialect to the parsed document**

`backend/src/Service/Parser/Model/FeedSyntax.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

enum FeedSyntax: string
{
    case Rss = 'rss';
    case Atom = 'atom';
}
```

`ParsedFeedModel` constructor, after `public ?string $generator = null,` add:

```php
        public ?FeedSyntax $syntax = null,
```

`Rss2Parser::parseFeed()` — the constructor call becomes:

```php
        return new ParsedFeedModel(
            PlainText::from($channel->text('title')),
            $channel->text('link'),
            $channel->text('description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
            $channel->text('generator'),
            FeedSyntax::Rss,
        )->withShowArtwork(PodcastArtwork::of($channelElement));
```

`Rss1Parser::parseFeed()`: add `syntax: FeedSyntax::Rss,` after `$entries,`. `AbstractAtomParser::parseFeed()`: add `syntax: FeedSyntax::Atom,` after `$entries,`. Each file imports `App\Service\Parser\Model\FeedSyntax`. (`withShowArtwork()` → `withEntries()` clones, so the syntax survives.)

- [ ] **Step 4: Offer a discovered feed as a candidate**

`backend/src/Service/Discovery/Model/FeedCandidateModel.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

final readonly class FeedCandidateModel
{
    /**
     * The dialect of a guessed candidate is unknown until something parses it,
     * so it is offered as a plain 'feed' — the dialog renders that as "Feed".
     */
    public const string GUESSED_FORMAT = 'feed';

    /**
     * @param string $format 'rss' or 'atom' when advertised or read, GUESSED_FORMAT when only guessed, else a
     *                       SourceFormat value
     */
    public function __construct(public string $url, public ?string $title, public string $format)
    {
    }

    public static function of(DiscoveredFeedModel $feed): self
    {
        return new self($feed->url, $feed->document->title, $feed->document->syntax?->value ?? self::GUESSED_FORMAT);
    }
}
```

In `FeedLinkScanner`, delete the `GUESSED_FORMAT` constant and its docblock (lines 34–38) and change line 99 to `new FeedCandidateModel($url, $labels->nameFor($ownLabel), FeedCandidateModel::GUESSED_FORMAT)`.

- [ ] **Step 5: Extract the preview builder**

Create `backend/src/Service/Preview/Factory/FeedPreviewFactory.php` by moving, verbatim, from `FeedPreviewService`: the constants `SAMPLE_SIZE`, `PREVIEW_ITEMS`, `FULL_TEXT_MIN`, `TIERS_BY_RICHNESS` with their comments, and the private methods `item()`, `httpsImageUrl()`, `tier()`, `verdict()`, `plainText()` with their comments. Its one public method is the tail of `preview()`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Preview\Factory;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Preview\Model\FeedPreviewItemModel;
use App\Service\Preview\Model\FeedPreviewModel;
use App\Service\Text\Support\PlainText;

final readonly class FeedPreviewFactory
{
    // SAMPLE_SIZE, PREVIEW_ITEMS, FULL_TEXT_MIN, TIERS_BY_RICHNESS: moved here unchanged, with their comments.

    public function fromDocument(ParsedFeedModel $feed): FeedPreviewModel
    {
        $sample = \array_slice($feed->entries, 0, self::SAMPLE_SIZE);
        $tiers = array_map(fn (ParsedEntryModel $entry): string => $this->tier($entry), $sample);
        $displayed = \array_slice($sample, 0, self::PREVIEW_ITEMS);
        $items = array_map(fn (ParsedEntryModel $entry): FeedPreviewItemModel => $this->item($entry), $displayed);

        return new FeedPreviewModel(
            title: $feed->title,
            itemCount: \count($feed->entries),
            content: $this->verdict($tiers),
            hasImages: array_any(
                $sample,
                fn (ParsedEntryModel $entry): bool => $this->httpsImageUrl($entry->media->image) !== null,
            ),
            items: $items,
        );
    }

    // item(), httpsImageUrl(), tier(), verdict(), plainText(): moved here unchanged, with their comments.
}
```

(The two `// …moved here` lines above are instructions, not code: the file holds the moved members in their place.)

`FeedPreviewService` keeps its class docblock and its fetch/parse/exception code. Its constructor gains `private FeedPreviewFactory $previewFactory,` as the last parameter; everything from `$sample = …` to the end of `preview()` becomes `return $this->previewFactory->fromDocument($feed);`; the moved constants and private methods go; unused imports go (`DeclaredImageModel`, `EntrySnippet`, `ParsedEntryModel`, `FeedPreviewItemModel`, `PlainText`); it imports `App\Service\Preview\Factory\FeedPreviewFactory`.

In `backend/tests/Service/Preview/FeedPreviewServiceTest.php` `service()`, pass the factory:

```php
        return new FeedPreviewService(
            $fetcher,
            $bodyParser,
            new ScrapeFallbackPolicy($readingSettings),
            new FeedPreviewFactory(),
        );
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Parser tests/Service/Discovery tests/Service/Preview tests/Controller/Api/FeedPreviewControllerTest.php`
Expected: PASS (the moved preview logic keeps every `FeedPreviewServiceTest` case green).

- [ ] **Step 7: Gates**

Run: `cd backend && composer check && composer md`
Expected: no findings; `FeedPreviewService.php`, `FeedPreviewFactory.php`, the three parsers, `ParsedFeedModel.php`, `FeedCandidateModel.php`, `FeedLinkScanner.php` PHPMD-clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Parser backend/src/Service/Discovery/Model/FeedCandidateModel.php \
  backend/src/Service/Discovery/FeedLinkScanner.php backend/src/Service/Preview \
  backend/tests/Service/Parser/FeedParserTest.php backend/tests/Service/Discovery/Model \
  backend/tests/Service/Preview
git commit -m "refactor(#2182): a parsed feed names its dialect and previews without a fetch"
```

---

### Task 2: `POST /api/feeds/discover` finds feeds and never subscribes

**Files:**
- Create: `backend/src/Service/Url/Support/EnteredUrl.php`
- Modify: `backend/src/Dto/Subscription/SubscribeRequest.php:36-58`
- Create: `backend/src/Dto/Feed/DiscoverFeedsRequest.php`
- Create: `backend/src/Service/Preview/Model/PreviewedCandidateModel.php`, `backend/src/Service/Preview/Model/PreviewedDiscoveryModel.php`
- Create: `backend/src/Service/Preview/PreviewedDiscovery.php`
- Modify: `backend/src/Http/FeedPreviewJson.php`
- Create: `backend/src/Http/PreviewedDiscoveryJson.php`
- Modify: `backend/src/Controller/Api/FeedPreviewController.php`
- Modify: `backend/config/packages/rate_limiter.yaml` (after `feed_preview`)
- Create: `backend/tests/Support/RecordingFeedDiscovery.php`
- Test: `backend/tests/Dto/Feed/DiscoverFeedsRequestTest.php`, `backend/tests/Service/Preview/PreviewedDiscoveryTest.php`, `backend/tests/Http/PreviewedDiscoveryJsonTest.php`, `backend/tests/Controller/Api/FeedPreviewDiscoverTest.php` (all new); `backend/tests/Dto/Subscription/SubscribeRequestTest.php` stays as is and must stay green

**Interfaces:**
- Consumes (Task 1): `FeedCandidateModel::of()`, `FeedPreviewFactory::fromDocument()`.
- Produces: `App\Service\Url\Support\EnteredUrl::withScheme(string $url): string`; `App\Dto\Feed\DiscoverFeedsRequest::$url`; `PreviewedCandidateModel(FeedCandidateModel $candidate, ?FeedPreviewModel $preview = null)`; `PreviewedDiscoveryModel(list<PreviewedCandidateModel> $candidates, ?ScrapeFailureReason $scrapeFailureReason = null)`; `PreviewedDiscovery::discover(User $user, string $url): PreviewedDiscoveryModel`; `FeedPreviewJson::preview(FeedPreviewModel): array` (the object under `feed`); `PreviewedDiscoveryJson::of(PreviewedDiscoveryModel): array`; route `api_feeds_discover`, `POST /api/feeds/discover`, body `{"url": string}`, `200 {"candidates": [{"url","title","format","preview"?}], "scrapeFailureReason"?}`; limiter `feed_discover`; test double `App\Tests\Support\RecordingFeedDiscovery` (`$urls`, `$fallbacks`).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Support/RecordingFeedDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFallback;

final class RecordingFeedDiscovery implements FeedDiscoveryInterface
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<ScrapeFallback> */
    public array $fallbacks = [];

    public function __construct(private readonly FeedDiscoveryResultModel $result)
    {
    }

    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
    {
        $this->urls[] = $url;
        $this->fallbacks[] = $fallback;

        return $this->result;
    }
}
```

`backend/tests/Dto/Feed/DiscoverFeedsRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Feed;

use App\Dto\Feed\DiscoverFeedsRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DiscoverFeedsRequestTest extends KernelTestCase
{
    private function validator(): ValidatorInterface
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return $validator;
    }

    public function testABareHostIsLookedUpOverHttps(): void
    {
        self::assertSame('https://heise.de', (new DiscoverFeedsRequest('  heise.de '))->url);
        self::assertCount(0, $this->validator()->validate(new DiscoverFeedsRequest('heise.de')));
    }

    public function testAnythingButAnHttpAddressFailsValidation(): void
    {
        self::assertGreaterThan(0, $this->validator()->validate(new DiscoverFeedsRequest('not a url'))->count());
        self::assertGreaterThan(0, $this->validator()->validate(new DiscoverFeedsRequest('ftp://example.com'))->count());
        self::assertGreaterThan(0, $this->validator()->validate(new DiscoverFeedsRequest(''))->count());
    }
}
```

`backend/tests/Service/Preview/PreviewedDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview;

use App\Entity\Headline;
use App\Entity\ReadingSettings;
use App\Repository\ReadingSettingsRepository;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFailureReason;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Parser\Model\FeedSyntax;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Preview\Factory\FeedPreviewFactory;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\PreviewedDiscovery;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingFeedDiscovery;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

final class PreviewedDiscoveryTest extends DbTestCase
{
    use SeedsUsers;

    private function service(FeedDiscoveryInterface $discovery): PreviewedDiscovery
    {
        $readingSettings = $this->entityManager->getRepository(ReadingSettings::class);
        self::assertInstanceOf(ReadingSettingsRepository::class, $readingSettings);

        return new PreviewedDiscovery($discovery, new ScrapeFallbackPolicy($readingSettings), new FeedPreviewFactory());
    }

    public function testADirectFeedIsOneCandidateCarryingThePreviewOfTheDocumentDiscoveryRead(): void
    {
        $entry = new ParsedEntryModel(
            guid: 'https://example.com/1',
            url: 'https://example.com/1',
            headline: Headline::fromFeed('First post'),
            author: null,
            summary: 'Teaser.',
            contentHtml: null,
            publishedAt: null,
        );
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::directFeed(new DiscoveredFeedModel(
            'https://example.com/feed.xml',
            new ParsedFeedModel('Example', null, null, null, [$entry], syntax: FeedSyntax::Atom),
        )));

        $found = $this->service($discovery)->discover($this->user('direct@example.test'), 'https://example.com/feed');

        self::assertCount(1, $found->candidates);
        self::assertEquals(
            new FeedCandidateModel('https://example.com/feed.xml', 'Example', 'atom'),
            $found->candidates[0]->candidate,
        );
        $preview = $found->candidates[0]->preview;
        self::assertNotNull($preview);
        self::assertSame(1, $preview->itemCount);
        self::assertSame('First post', $preview->items[0]->title);
        self::assertNull($found->scrapeFailureReason);
        self::assertSame(['https://example.com/feed'], $discovery->urls);
    }

    public function testAPagesCandidatesComeWithoutPreviews(): void
    {
        $candidate = new FeedCandidateModel('https://example.com/rss.xml', 'Main', 'rss');
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([$candidate]));

        $found = $this->service($discovery)->discover($this->user('page@example.test'), 'https://example.com/');

        self::assertEquals([new PreviewedCandidateModel($candidate)], $found->candidates);
        self::assertNull($found->scrapeFailureReason);
    }

    public function testAScrapeFailureCarriesItsReason(): void
    {
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Blocked));

        $found = $this->service($discovery)->discover($this->user('blocked@example.test'), 'https://example.com/');

        self::assertSame([], $found->candidates);
        self::assertSame(ScrapeFailureReason::Blocked, $found->scrapeFailureReason);
    }

    public function testDiscoveryFollowsTheAccountsScrapePreference(): void
    {
        $off = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));
        $this->service($off)->discover($this->user('scrape-off@example.test'), 'https://example.com/');

        $user = $this->user('scrape-on@example.test');
        StoredUserSettings::reading($this->entityManager, $user)->setScrapeFallbackEnabled(true);
        $this->entityManager->flush();
        $on = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));
        $this->service($on)->discover($user, 'https://example.com/');

        self::assertSame([ScrapeFallback::Disabled], $off->fallbacks);
        self::assertSame([ScrapeFallback::Enabled], $on->fallbacks);
    }
}
```

`backend/tests/Http/PreviewedDiscoveryJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\FeedPreviewJson;
use App\Http\PreviewedDiscoveryJson;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\ScrapeFailureReason;
use App\Service\Preview\Model\FeedPreviewModel;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;
use PHPUnit\Framework\TestCase;

final class PreviewedDiscoveryJsonTest extends TestCase
{
    public function testACandidateWithoutAPreviewCarriesOnlyItsAddress(): void
    {
        $found = new PreviewedDiscoveryModel([
            new PreviewedCandidateModel(new FeedCandidateModel('https://example.com/rss', 'Example', 'rss')),
            new PreviewedCandidateModel(new FeedCandidateModel('https://example.com/atom', null, 'atom')),
        ]);

        self::assertSame(
            ['candidates' => [
                ['url' => 'https://example.com/rss', 'title' => 'Example', 'format' => 'rss'],
                ['url' => 'https://example.com/atom', 'title' => null, 'format' => 'atom'],
            ]],
            PreviewedDiscoveryJson::of($found),
        );
    }

    public function testAPreviewedCandidateCarriesThePreviewEndpointsShape(): void
    {
        $preview = new FeedPreviewModel('Example', 0, 'title-only', false, []);
        $found = new PreviewedDiscoveryModel([new PreviewedCandidateModel(
            new FeedCandidateModel('https://example.com/feed.xml', 'Example', 'rss'),
            $preview,
        )]);

        self::assertSame(
            ['candidates' => [[
                'url' => 'https://example.com/feed.xml',
                'title' => 'Example',
                'format' => 'rss',
                'preview' => FeedPreviewJson::one($preview)['feed'],
            ]]],
            PreviewedDiscoveryJson::of($found),
        );
    }

    public function testCarriesTheScrapeFailureReasonWhenThereIsOne(): void
    {
        self::assertSame(
            ['candidates' => [], 'scrapeFailureReason' => 'blocked'],
            PreviewedDiscoveryJson::of(new PreviewedDiscoveryModel([], ScrapeFailureReason::Blocked)),
        );
    }
}
```

`backend/tests/Controller/Api/FeedPreviewDiscoverTest.php` (the four discovery-outcome cases come over from `SubscriptionControllerTest` lines 348–483, which Task 6 removes there):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Service\Scraper\ScrapedFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
use App\Tests\Support\StubFeedFetcher;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class FeedPreviewDiscoverTest extends ApiTestCase
{
    use ScrapedFixtures;

    private const int DISCOVER_BUDGET = 60;

    protected function setUp(): void
    {
        // The feed_discover limiter counts in a FILESYSTEM pool that outlives the run, as feed_preview's does.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /** The real FeedDiscovery runs; only its fetcher is a stub. Call before the request, on the kernel's first boot. */
    private function installFetcher(StubFeedFetcher $stub): void
    {
        $stub->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));
        self::getContainer()->set(FeedFetcherInterface::class, $stub);
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);
    }

    private function serving(string $url, string $finalUrl, string $body): StubFeedFetcher
    {
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            $url,
            FetchResponseModel::fetched($finalUrl, permanentRedirect: false, body: $body, etag: null, lastModified: null),
        );

        return $stub;
    }

    private function rss(): string
    {
        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);

        return $rss;
    }

    /** @return array<string, mixed> */
    private function discover(KernelBrowser $client, User $user, string $url): array
    {
        $client->request(
            'POST',
            '/api/feeds/discover',
            server: $this->jsonBearerFor($user),
            content: json_encode(['url' => $url], \JSON_THROW_ON_ERROR),
        );

        return $this->payload($client);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/feeds/discover',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://example.com'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testADirectFeedComesBackAsOneCandidateCarryingItsPreviewAndNothingIsSubscribed(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-direct@example.com');
        $this->installFetcher($this->serving('https://example.com/feed', 'https://example.com/feed.xml', $this->rss()));

        $body = $this->discover($client, $user, 'https://example.com/feed');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertArrayNotHasKey('scrapeFailureReason', $body);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertCount(1, $candidates);
        $candidate = $candidates[0];
        self::assertIsArray($candidate);
        self::assertSame('https://example.com/feed.xml', $candidate['url']);
        self::assertSame('Example Tech Blog', $candidate['title']);
        self::assertSame('rss', $candidate['format']);
        self::assertArrayNotHasKey('noPreviewReason', $candidate);
        $preview = $candidate['preview'] ?? null;
        self::assertIsArray($preview);
        self::assertSame('Example Tech Blog', $preview['title']);
        self::assertSame(2, $preview['itemCount']);
        self::assertIsArray($preview['items']);
        self::assertCount(2, $preview['items']);
        self::assertSame(0, $this->entityManager()->getRepository(Subscription::class)->count([]));
    }

    public function testABareHostIsLookedUpOverHttps(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-bare@example.com');
        $this->installFetcher($this->serving('https://example.com/feed', 'https://example.com/feed', $this->rss()));

        $body = $this->discover($client, $user, 'example.com/feed');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame('https://example.com/feed', $candidates[0]['url']);
    }

    public function testAPageOffersItsAdvertisedFeedsWithoutPreviews(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-page@example.com');
        $html = /** @lang TEXT */ '<!doctype html><html><head>'
            . '<link rel="alternate" type="application/rss+xml" href="/rss.xml">'
            . '</head><body>x</body></html>';
        // The trailing slash makes the relative /rss.xml resolve to the site root.
        $this->installFetcher($this->serving('https://example.com/blog', 'https://example.com/blog/', $html));

        $body = $this->discover($client, $user, 'https://example.com/blog');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        $candidate = $candidates[0];
        self::assertIsArray($candidate);
        self::assertSame('https://example.com/rss.xml', $candidate['url']);
        self::assertSame('rss', $candidate['format']);
        self::assertArrayNotHasKey('preview', $candidate);
        self::assertArrayNotHasKey('scrapeFailureReason', $body);
    }

    public function testAFeedlessPageOffersAScrapedCandidate(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-feedless@example.com');
        StoredUserSettings::reading($this->entityManager(), $user)->setScrapeFallbackEnabled(true);
        $this->entityManager()->flush();
        $this->installFetcher(
            $this->serving('https://www.heise.de', 'https://www.heise.de/', $this->scrapedFixture('heise-2026-07-23.html')),
        );

        $body = $this->discover($client, $user, 'https://www.heise.de');

        self::assertSame(
            ['candidates' => [
                ['url' => 'https://www.heise.de/', 'title' => 'heise online', 'format' => 'scraped'],
            ]],
            $body,
        );
    }

    /** With the preference at its default, off, a wiring that never consults ScrapeFallbackPolicy would scrape. */
    public function testAFeedlessPageOffersNoScrapedCandidateWhenTheFallbackIsDisabled(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-feedless-off@example.com');
        $this->installFetcher(
            $this->serving('https://www.heise.de', 'https://www.heise.de/', $this->scrapedFixture('heise-2026-07-23.html')),
        );

        self::assertSame(['candidates' => []], $this->discover($client, $user, 'https://www.heise.de'));
    }

    public function testABlockedSiteReportsItsReason(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-blocked@example.com');
        $stub = new StubFeedFetcher();
        $stub->willThrow('https://forbidden.example.com', new FeedUnreachableException('x: HTTP 403', 403));
        $this->installFetcher($stub);

        $body = $this->discover($client, $user, 'https://forbidden.example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['candidates' => [], 'scrapeFailureReason' => 'blocked'], $body);
    }

    public function testDiscoverDrawsOnItsOwnPerUserBudget(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = $this->factory()->create('discover-budget@example.com');
        $this->installFetcher($this->serving('https://example.com/feed', 'https://example.com/feed', $this->rss()));
        for ($attempt = 0; $attempt < self::DISCOVER_BUDGET; ++$attempt) {
            $this->discover($client, $user, 'https://example.com/feed');
            self::assertResponseIsSuccessful();
        }

        $client->request(
            'POST',
            '/api/feeds/discover',
            server: $this->jsonBearerFor($user),
            content: json_encode(['url' => 'https://example.com/feed'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_TOO_MANY_REQUESTS);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Dto/Feed tests/Service/Preview/PreviewedDiscoveryTest.php tests/Http/PreviewedDiscoveryJsonTest.php tests/Controller/Api/FeedPreviewDiscoverTest.php`
Expected: FAIL — `Class "App\Dto\Feed\DiscoverFeedsRequest" not found`, `Class "App\Service\Preview\PreviewedDiscovery" not found`, and 404s from the controller test.

- [ ] **Step 3: Share the bare-host normalisation**

`backend/src/Service/Url/Support/EnteredUrl.php` — the body is `SubscribeRequest::normalizeUrl()` moved verbatim, with its docblock and its one comment:

```php
<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

final class EnteredUrl
{
    /**
     * Prefixes `https://` to a bare host so "example.com" passes the Url constraint. A value with any scheme stays as
     * it is, so `ftp://` still fails the protocol check.
     */
    public static function withScheme(string $url): string
    {
        $url = trim($url);
        if ('' === $url || 1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            return $url;
        }

        // Strip a protocol-relative "//" so it does not become "https:////host".
        return 'https://' . ltrim($url, '/');
    }

    private function __construct()
    {
    }
}
```

In `SubscribeRequest`, delete `normalizeUrl()` with its docblock, import `App\Service\Url\Support\EnteredUrl`, and set `$this->url = EnteredUrl::withScheme($url);`.

`backend/src/Dto/Feed/DiscoverFeedsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Feed;

use App\Service\Url\Support\EnteredUrl;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DiscoverFeedsRequest
{
    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 750)]
    public string $url;

    public function __construct(string $url = '')
    {
        $this->url = EnteredUrl::withScheme($url);
    }
}
```

- [ ] **Step 4: The discover service and its models**

`backend/src/Service/Preview/Model/PreviewedCandidateModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Preview\Model;

use App\Service\Discovery\Model\FeedCandidateModel;

final readonly class PreviewedCandidateModel
{
    public function __construct(public FeedCandidateModel $candidate, public ?FeedPreviewModel $preview = null)
    {
    }
}
```

`backend/src/Service/Preview/Model/PreviewedDiscoveryModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Preview\Model;

use App\Service\Discovery\Model\ScrapeFailureReason;

final readonly class PreviewedDiscoveryModel
{
    /** @param list<PreviewedCandidateModel> $candidates an empty list is a legitimate outcome; the reason says why */
    public function __construct(public array $candidates, public ?ScrapeFailureReason $scrapeFailureReason = null)
    {
    }
}
```

`backend/src/Service/Preview/PreviewedDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Preview;

use App\Entity\User;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Preview\Factory\FeedPreviewFactory;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;

final readonly class PreviewedDiscovery
{
    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FeedPreviewFactory $previewFactory,
    ) {
    }

    public function discover(User $user, string $url): PreviewedDiscoveryModel
    {
        $result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));
        $feed = $result->feed;
        if (null !== $feed) {
            return new PreviewedDiscoveryModel([$this->previewed($feed)]);
        }

        return new PreviewedDiscoveryModel(
            array_map(
                static fn (FeedCandidateModel $candidate): PreviewedCandidateModel => new PreviewedCandidateModel(
                    $candidate,
                ),
                $result->candidates,
            ),
            $result->scrapeFailureReason,
        );
    }

    private function previewed(DiscoveredFeedModel $feed): PreviewedCandidateModel
    {
        return new PreviewedCandidateModel(
            FeedCandidateModel::of($feed),
            $this->previewFactory->fromDocument($feed->document),
        );
    }
}
```

- [ ] **Step 5: The response mappers**

In `FeedPreviewJson`, move the inner array into a public `preview()` that carries the existing item-shape docblock; `one()` wraps it:

```php
    /** @return array{feed: array<string, mixed>} */
    public static function one(FeedPreviewModel $preview): array
    {
        return ['feed' => self::preview($preview)];
    }

    /**
     * @return array{
     *   title: string|null,
     *   itemCount: int,
     *   content: string,
     *   hasImages: bool,
     *   items: list<array{
     *     title: string,
     *     url: string|null,
     *     author: string|null,
     *     summary: string|null,
     *     imageUrl: string|null,
     *     imageWidth: int|null,
     *     imageHeight: int|null,
     *     publishedAt: string|null,
     *   }>,
     * }
     */
    public static function preview(FeedPreviewModel $preview): array
    {
        return [
            'title' => $preview->title,
            'itemCount' => $preview->itemCount,
            'content' => $preview->content,
            'hasImages' => $preview->hasImages,
            'items' => array_map(
                static fn (FeedPreviewItemModel $item) => [
                    'title' => $item->title,
                    'url' => $item->url,
                    'author' => $item->author,
                    'summary' => $item->summary,
                    'imageUrl' => $item->imageUrl,
                    'imageWidth' => $item->imageWidth,
                    'imageHeight' => $item->imageHeight,
                    'publishedAt' => $item->publishedAt?->format(\DateTimeInterface::ATOM),
                ],
                $preview->items,
            ),
        ];
    }
```

`backend/src/Http/PreviewedDiscoveryJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;

final class PreviewedDiscoveryJson
{
    /** @return array<string, mixed> */
    public static function of(PreviewedDiscoveryModel $found): array
    {
        $payload = ['candidates' => array_map(self::candidate(...), $found->candidates)];
        if (null !== $found->scrapeFailureReason) {
            $payload['scrapeFailureReason'] = $found->scrapeFailureReason->value;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function candidate(PreviewedCandidateModel $previewed): array
    {
        $candidate = $previewed->candidate;
        $json = ['url' => $candidate->url, 'title' => $candidate->title, 'format' => $candidate->format];
        if (null !== $previewed->preview) {
            $json['preview'] = FeedPreviewJson::preview($previewed->preview);
        }

        return $json;
    }
}
```

- [ ] **Step 6: The route and its limiter**

`backend/config/packages/rate_limiter.yaml`, after the `feed_preview` block:

```yaml
        # Per user, its own budget: a discover fetches a page and may probe a few feed addresses under it (#2182).
        feed_discover:
            policy: 'sliding_window'
            limit: 60
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

`FeedPreviewController`: constructor becomes

```php
    public function __construct(
        private FeedPreviewService $previews,
        private PreviewedDiscovery $discoveries,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $feedPreviewLimiter,
        #[Target]
        private RateLimiterFactoryInterface $feedDiscoverLimiter,
    ) {
    }
```

and it gains (imports `App\Dto\Feed\DiscoverFeedsRequest`, `App\Http\PreviewedDiscoveryJson`, `App\Service\Preview\PreviewedDiscovery`):

```php
    #[Route('/discover', name: 'api_feeds_discover', methods: ['POST'])]
    public function discover(
        #[CurrentUser] User $user,
        #[MapRequestPayload] DiscoverFeedsRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->feedDiscoverLimiter, $user);

        return new JsonResponse(PreviewedDiscoveryJson::of($this->discoveries->discover($user, $request->url)));
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Dto tests/Service/Preview tests/Http tests/Controller/Api/FeedPreviewDiscoverTest.php tests/Controller/Api/FeedPreviewControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS (`SubscriptionControllerTest` still passes: subscribe is unchanged until Task 6).

- [ ] **Step 8: Gates**

Run: `cd backend && composer check && composer md`
Expected: clean, including `ServiceModuleCycleRule` (new `Preview → Discovery` edges only) and `ServiceRoleRule` (`Model/`, `Factory/`, `Support/` placements).

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Url/Support/EnteredUrl.php backend/src/Dto backend/src/Service/Preview \
  backend/src/Http/FeedPreviewJson.php backend/src/Http/PreviewedDiscoveryJson.php \
  backend/src/Controller/Api/FeedPreviewController.php backend/config/packages/rate_limiter.yaml \
  backend/tests/Support/RecordingFeedDiscovery.php backend/tests/Dto/Feed backend/tests/Service/Preview \
  backend/tests/Http/PreviewedDiscoveryJsonTest.php backend/tests/Controller/Api/FeedPreviewDiscoverTest.php
git commit -m "feat(#2182): POST /api/feeds/discover finds feeds with an inline preview and never subscribes"
```

---

### Task 3: Discover offers a hidden Bluesky profile without a preview

**Files:**
- Create: `backend/src/Service/Discovery/Model/NoPreviewReason.php`
- Modify: `backend/src/Service/Discovery/Model/FeedCandidateModel.php`
- Create: `backend/src/Service/Discovery/AccountOnlyFeed/AccountOnlyFeedInterface.php`
- Modify: `backend/config/services.yaml` (`_instanceof`, after `FeedOfferInterface`)
- Modify: `backend/src/Service/Social/TolerantHiddenProfiles.php`, `backend/src/Service/Social/HiddenProfileSubscribeRoute.php:33-40`
- Create: `backend/src/Service/Social/HiddenProfileAccountOnlyFeed.php`
- Modify: `backend/src/Service/Preview/PreviewedDiscovery.php`, `backend/src/Http/PreviewedDiscoveryJson.php`
- Test: `backend/tests/Service/Social/HiddenProfileAccountOnlyFeedTest.php` (new), `backend/tests/Service/Preview/PreviewedDiscoveryTest.php`, `backend/tests/Http/PreviewedDiscoveryJsonTest.php`, `backend/tests/Controller/Api/FeedPreviewDiscoverTest.php`; `backend/tests/Service/Social/HiddenProfileSubscribeRouteTest.php` and `backend/tests/Controller/Api/SubscriptionHiddenProfileTest.php` stay green unchanged

**Interfaces:**
- Consumes (Task 2): `PreviewedDiscovery`, `PreviewedDiscoveryJson`, `FeedPreviewDiscoverTest` helpers.
- Produces: `enum App\Service\Discovery\Model\NoPreviewReason: string { case HiddenProfile = 'hidden_profile'; case ConnectBlueskyAccount = 'connect_bluesky_account'; }`; `FeedCandidateModel::$noPreviewReason: ?NoPreviewReason` (4th constructor parameter, default null); `interface App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface { public function candidateFor(User $user, string $url): ?FeedCandidateModel; }` tagged `app.account_only_feed`; `HiddenProfileAccountOnlyFeed::__construct(TolerantHiddenProfiles, SocialAccountRepository)`; `TolerantHiddenProfiles::hiddenAt(string $url, string $warning): ?HiddenProfileModel`; `PreviewedDiscovery::__construct(FeedDiscoveryInterface, ScrapeFallbackPolicy, FeedPreviewFactory, iterable<AccountOnlyFeedInterface>)`; JSON key `noPreviewReason`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Social/HiddenProfileAccountOnlyFeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\NoPreviewReason;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfileAccountOnlyFeed;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\TolerantHiddenProfiles;
use App\Tests\DbTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\ConnectsBlueskyAccounts;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubHiddenProfiles;

final class HiddenProfileAccountOnlyFeedTest extends DbTestCase
{
    use ConnectsBlueskyAccounts;
    use SeedsUsers;

    private const string PAGE = 'https://bsky.app/profile/rusty-17.bsky.social';

    private User $user;
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user('hidden-discover@example.test');
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
        $this->logger = new RecordingLogger();
    }

    public function testAHiddenProfileTheConnectedAccountCanReadIsOfferedWithoutAPreview(): void
    {
        $this->blueskyAccountOf($this->user);

        self::assertEquals(
            new FeedCandidateModel(self::PAGE, 'Rusty', SourceFormat::BLUESKY_API, NoPreviewReason::HiddenProfile),
            $this->feed()->candidateFor($this->user, self::PAGE),
        );
    }

    public function testWithoutAConnectedAccountTheCandidateSaysToConnectOne(): void
    {
        self::assertEquals(
            new FeedCandidateModel(
                self::PAGE,
                'Rusty',
                SourceFormat::BLUESKY_API,
                NoPreviewReason::ConnectBlueskyAccount,
            ),
            $this->feed()->candidateFor($this->user, self::PAGE),
        );
    }

    public function testAnAccountThatNeedsAReconnectCannotReadTheProfile(): void
    {
        $account = $this->blueskyAccountOf($this->user);
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        self::assertSame(
            NoPreviewReason::ConnectBlueskyAccount,
            $this->feed()->candidateFor($this->user, self::PAGE)?->noPreviewReason,
        );
    }

    public function testAPublicProfileIsLeftToDiscovery(): void
    {
        self::assertNull($this->feed()->candidateFor($this->user, 'https://bsky.app/profile/bob.bsky.social'));
    }

    public function testAnyOtherUrlIsLeftToDiscoveryUnasked(): void
    {
        self::assertNull($this->feed()->candidateFor($this->user, 'https://example.com/feed'));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredLabelCheckIsLeftToDiscoveryAndLogged(): void
    {
        $failure = new SocialNetworkUnreachableException('down');
        $this->hiddenProfiles->failure = $failure;

        self::assertNull($this->feed()->candidateFor($this->user, self::PAGE));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertSame($failure, $this->logger->records[0]['context']['exception'] ?? null);
    }

    private function feed(): HiddenProfileAccountOnlyFeed
    {
        $socialAccounts = self::getContainer()->get(SocialAccountRepository::class);
        self::assertInstanceOf(SocialAccountRepository::class, $socialAccounts);

        return new HiddenProfileAccountOnlyFeed(
            new TolerantHiddenProfiles($this->hiddenProfiles, $this->logger),
            $socialAccounts,
        );
    }
}
```

In `PreviewedDiscoveryTest`, the helper takes the account-only feeds (imports `App\Entity\User`, `App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface`, `App\Enum\SourceFormat`, `App\Service\Discovery\Model\NoPreviewReason`), and two tests are added:

```php
    /** @param list<AccountOnlyFeedInterface> $accountOnlyFeeds */
    private function service(FeedDiscoveryInterface $discovery, array $accountOnlyFeeds = []): PreviewedDiscovery
    {
        $readingSettings = $this->entityManager->getRepository(ReadingSettings::class);
        self::assertInstanceOf(ReadingSettingsRepository::class, $readingSettings);

        return new PreviewedDiscovery(
            $discovery,
            new ScrapeFallbackPolicy($readingSettings),
            new FeedPreviewFactory(),
            $accountOnlyFeeds,
        );
    }

    private function offering(?FeedCandidateModel $candidate): AccountOnlyFeedInterface
    {
        return new readonly class ($candidate) implements AccountOnlyFeedInterface {
            public function __construct(private ?FeedCandidateModel $candidate)
            {
            }

            public function candidateFor(User $user, string $url): ?FeedCandidateModel
            {
                return $this->candidate;
            }
        };
    }

    public function testAnAccountOnlyFeedAnswersBeforeDiscoveryIsAsked(): void
    {
        $hidden = new FeedCandidateModel(
            'https://bsky.app/profile/rusty-17.bsky.social',
            'Rusty',
            SourceFormat::BLUESKY_API,
            NoPreviewReason::HiddenProfile,
        );
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));

        $found = $this->service($discovery, [$this->offering(null), $this->offering($hidden)])
            ->discover($this->user('hidden@example.test'), 'https://bsky.app/profile/rusty-17.bsky.social');

        self::assertEquals([new PreviewedCandidateModel($hidden)], $found->candidates);
        self::assertSame([], $discovery->urls);
    }

    public function testADecliningAccountOnlyFeedLeavesTheUrlToDiscovery(): void
    {
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));

        $this->service($discovery, [$this->offering(null)])
            ->discover($this->user('declined@example.test'), 'https://example.com/');

        self::assertSame(['https://example.com/'], $discovery->urls);
    }
```

Add to `PreviewedDiscoveryJsonTest` (imports `App\Enum\SourceFormat`, `App\Service\Discovery\Model\NoPreviewReason`):

```php
    public function testACandidateNoPreviewCanBeFetchedForSaysWhy(): void
    {
        $found = new PreviewedDiscoveryModel([new PreviewedCandidateModel(new FeedCandidateModel(
            'https://bsky.app/profile/rusty-17.bsky.social',
            'Rusty',
            SourceFormat::BLUESKY_API,
            NoPreviewReason::HiddenProfile,
        ))]);

        self::assertSame(
            ['candidates' => [[
                'url' => 'https://bsky.app/profile/rusty-17.bsky.social',
                'title' => 'Rusty',
                'format' => 'bluesky-api',
                'noPreviewReason' => 'hidden_profile',
            ]]],
            PreviewedDiscoveryJson::of($found),
        );
    }

    public function testACandidateThatNeedsAConnectedAccountSaysSo(): void
    {
        $found = new PreviewedDiscoveryModel([new PreviewedCandidateModel(new FeedCandidateModel(
            'https://bsky.app/profile/rusty-17.bsky.social',
            'Rusty',
            SourceFormat::BLUESKY_API,
            NoPreviewReason::ConnectBlueskyAccount,
        ))]);

        $candidates = PreviewedDiscoveryJson::of($found)['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame('connect_bluesky_account', $candidates[0]['noPreviewReason']);
    }
```

Add to `FeedPreviewDiscoverTest` (imports `App\Service\Social\Model\HiddenProfileModel`, `App\Tests\Support\BlueskyProfiles`, `App\Tests\Support\SocialAccounts`, `App\Tests\Support\StubHiddenProfiles`):

```php
    private function hideRusty(): void
    {
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
    }

    public function testAHiddenBlueskyProfileTheAccountCanReadIsOfferedWithoutAPreviewAndSaysWhy(): void
    {
        $client = self::createClient();
        $this->hideRusty();
        $user = $this->factory()->create('discover-hidden@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();
        $this->installFetcher(new StubFeedFetcher());

        $body = $this->discover($client, $user, 'https://bsky.app/profile/rusty-17.bsky.social');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(
            ['candidates' => [[
                'url' => 'https://bsky.app/profile/rusty-17.bsky.social',
                'title' => 'Rusty',
                'format' => 'bluesky-api',
                'noPreviewReason' => 'hidden_profile',
            ]]],
            $body,
        );
    }

    public function testWithoutAConnectedBlueskyAccountTheHiddenProfileSaysToConnectOne(): void
    {
        $client = self::createClient();
        $this->hideRusty();
        $user = $this->factory()->create('discover-hidden-no-account@example.test');
        $this->installFetcher(new StubFeedFetcher());

        $body = $this->discover($client, $user, 'https://bsky.app/profile/rusty-17.bsky.social');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame('connect_bluesky_account', $candidates[0]['noPreviewReason']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/HiddenProfileAccountOnlyFeedTest.php tests/Service/Preview/PreviewedDiscoveryTest.php tests/Http/PreviewedDiscoveryJsonTest.php tests/Controller/Api/FeedPreviewDiscoverTest.php`
Expected: FAIL — `Class "App\Service\Discovery\Model\NoPreviewReason" not found`, `Interface "App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface" not found`.

- [ ] **Step 3: The Discovery-owned seam**

`backend/src/Service/Discovery/Model/NoPreviewReason.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

enum NoPreviewReason: string
{
    case HiddenProfile = 'hidden_profile';
    case ConnectBlueskyAccount = 'connect_bluesky_account';
}
```

`FeedCandidateModel` constructor gains a fourth promoted parameter:

```php
    public function __construct(
        public string $url,
        public ?string $title,
        public string $format,
        public ?NoPreviewReason $noPreviewReason = null,
    ) {
    }
```

`backend/src/Service/Discovery/AccountOnlyFeed/AccountOnlyFeedInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\AccountOnlyFeed;

use App\Entity\User;
use App\Service\Discovery\Model\FeedCandidateModel;

/** A source offering some URLs as a feed only a connected account can read, asked before discovery fetches. */
interface AccountOnlyFeedInterface
{
    /** @return FeedCandidateModel|null null when the URL is not this source's to offer */
    public function candidateFor(User $user, string $url): ?FeedCandidateModel;
}
```

`backend/config/services.yaml`, under `_instanceof`, after the `FeedOfferInterface` entry:

```yaml
        App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface:
            tags: ['app.account_only_feed']
```

- [ ] **Step 4: Social implements it, sharing the hidden-profile lookup with the subscribe route**

`TolerantHiddenProfiles` gains (imports `App\Service\Social\Support\ProfileFeedUrl`):

```php
    /** The hidden profile a Bluesky profile URL names; null for any other URL, and when Bluesky cannot say. */
    public function hiddenAt(string $url, string $warning): ?HiddenProfileModel
    {
        $actor = ProfileFeedUrl::blueskyProfileActorOf($url);
        if (null === $actor) {
            return null;
        }

        return $this->hiddenAmongOrNone([$actor], $warning)[$actor] ?? null;
    }
```

`HiddenProfileSubscribeRoute::subscribe()` starts with (and drops its `ProfileFeedUrl` import):

```php
        $profile = $this->hiddenProfiles->hiddenAt(
            $request->url,
            'Subscribe could not tell whether a Bluesky profile is hidden; discovery decides.',
        );
        if (null === $profile) {
            return null;
        }
```

`backend/src/Service/Social/HiddenProfileAccountOnlyFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\NoPreviewReason;

final readonly class HiddenProfileAccountOnlyFeed implements AccountOnlyFeedInterface
{
    public function __construct(
        private TolerantHiddenProfiles $hiddenProfiles,
        private SocialAccountRepository $socialAccounts,
    ) {
    }

    public function candidateFor(User $user, string $url): ?FeedCandidateModel
    {
        $profile = $this->hiddenProfiles->hiddenAt(
            $url,
            'Discover could not tell whether a Bluesky profile is hidden; discovery decides.',
        );
        if (null === $profile) {
            return null;
        }

        return new FeedCandidateModel($url, $profile->name, SourceFormat::BLUESKY_API, $this->reasonFor($user));
    }

    private function reasonFor(User $user): NoPreviewReason
    {
        return null === $this->socialAccounts->findReadyActingOnNetwork($user, SocialNetwork::Bluesky)
            ? NoPreviewReason::ConnectBlueskyAccount
            : NoPreviewReason::HiddenProfile;
    }
}
```

- [ ] **Step 5: Discover asks the account-only feeds first**

`PreviewedDiscovery` (imports `App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface`, `Symfony\Component\DependencyInjection\Attribute\AutowireIterator`):

```php
    /** @param iterable<AccountOnlyFeedInterface> $accountOnlyFeeds */
    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FeedPreviewFactory $previewFactory,
        #[AutowireIterator('app.account_only_feed')]
        private iterable $accountOnlyFeeds,
    ) {
    }

    public function discover(User $user, string $url): PreviewedDiscoveryModel
    {
        $accountOnly = $this->accountOnlyCandidate($user, $url);
        if (null !== $accountOnly) {
            return new PreviewedDiscoveryModel([new PreviewedCandidateModel($accountOnly)]);
        }

        // …the Task 2 body from `$result = $this->discovery->discover(…)` on, unchanged.
    }

    private function accountOnlyCandidate(User $user, string $url): ?FeedCandidateModel
    {
        foreach ($this->accountOnlyFeeds as $accountOnlyFeed) {
            $candidate = $accountOnlyFeed->candidateFor($user, $url);
            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }
```

`PreviewedDiscoveryJson::candidate()`, before `return $json;`:

```php
        if (null !== $candidate->noPreviewReason) {
            $json['noPreviewReason'] = $candidate->noPreviewReason->value;
        }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Service/Preview tests/Http tests/Controller/Api/FeedPreviewDiscoverTest.php tests/Controller/Api/SubscriptionHiddenProfileTest.php tests/Service/Discovery`
Expected: PASS.

- [ ] **Step 7: Gates**

Run: `cd backend && composer check && composer md`
Expected: clean. `ServiceModuleCycleRule` sees a new `Social → Discovery` edge and no cycle (Discovery reaches neither Social nor Preview).

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Discovery backend/src/Service/Social backend/src/Service/Preview/PreviewedDiscovery.php \
  backend/src/Http/PreviewedDiscoveryJson.php backend/config/services.yaml \
  backend/tests/Service/Social/HiddenProfileAccountOnlyFeedTest.php backend/tests/Service/Preview \
  backend/tests/Http/PreviewedDiscoveryJsonTest.php backend/tests/Controller/Api/FeedPreviewDiscoverTest.php
git commit -m "feat(#2182): discover offers a hidden Bluesky profile without a preview and says why"
```

---

### Task 4: The add-feed dialog discovers on Add and subscribes from a card

**Files:**
- Modify: `frontend/src/app/reader/models.ts:334-374`
- Modify: `frontend/src/app/reader/reader-api.ts` (imports, after `subscribe()`)
- Modify: `frontend/src/app/reader/reader-api.spec.ts`
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.ts`, `.html`, `.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`dialog.addFeed`)
- Modify: `docs/features.md:78-79`

**Interfaces:**
- Consumes (Tasks 2–3): `POST /api/feeds/discover` `{url}` → `{candidates: [{url, title, format, preview?, noPreviewReason?}], scrapeFailureReason?}`; `preview` has the `FeedPreview` shape.
- Produces: `type NoPreviewReason = 'hidden_profile' | 'connect_bluesky_account' | (string & {})`; `FeedCandidate.preview?: FeedPreview`, `FeedCandidate.noPreviewReason?: NoPreviewReason`; `interface DiscoverResult { candidates: FeedCandidate[]; scrapeFailureReason?: ScrapeFailureReason }`; `SubscribeResult = { subscription: SubscriptionDto }`; `ReaderApi.discoverFeeds(url: string): Observable<DiscoverResult>`; component signal `subscribing`, method `noPreviewText(reason)`; CSS hook `.no-preview`.

- [ ] **Step 1: Write the failing tests**

`reader-api.spec.ts`, after `'POSTs a subscribe URL'`:

```ts
  it('POSTs the address to discover, and only the address', () => {
    let result: unknown;
    api.discoverFeeds('https://example.com').subscribe((response) => (result = response));
    const testRequest = ctrl.expectOne('https://api.test/api/feeds/discover');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ url: 'https://example.com' });
    testRequest.flush({ candidates: [] });

    expect(result).toEqual({ candidates: [] });
  });
```

`add-feed-dialog.component.spec.ts`. Add, at the top of the `describe` block:

```ts
  const RSS_PREVIEW = {
    title: 'RSS Feed',
    itemCount: 1,
    content: 'full',
    hasImages: false,
    items: [
      {
        title: 'Inline headline',
        url: 'https://f/rss/1',
        publishedAt: null,
        author: null,
        summary: 'snip',
        imageUrl: null,
        imageWidth: null,
        imageHeight: null,
      },
    ],
  };

  function subscribeButton(fixture: { nativeElement: unknown }): HTMLButtonElement {
    return (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('.card .subscribe')!;
  }
```

Replace `'closes with the created subscription'` with:

```ts
  it('subscribes the picked card and closes with the created subscription', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://example.com/feed' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss', preview: RSS_PREVIEW }],
    });
    fixture.detectChanges();
    expect(close).not.toHaveBeenCalled();

    subscribeButton(fixture).click();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });

  it("shows a discovered feed's inline preview without asking for one", () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://f/rss' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss', preview: RSS_PREVIEW }],
    });
    fixture.detectChanges();

    ctrl.expectNone((request) => request.url.endsWith('/api/feeds/preview'));
    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.textContent).toContain('Inline headline');
    expect(card.querySelector('.count')?.textContent).toContain('1 items');
  });

  it('keeps the cards and shows the refusal in the field when a card cannot be subscribed', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://f/rss' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss', preview: RSS_PREVIEW }],
    });
    fixture.detectChanges();

    subscribeButton(fixture).click();
    fixture.detectChanges();
    expect(subscribeButton(fixture).disabled).toBe(true);
    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      {
        type: 'not_a_feed',
        title: 'Not a feed',
        status: 422,
        detail:
          'No feed could be read at that address. POST it to /api/feeds/discover for the feeds it offers.',
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.error()).toContain('No feed could be read');
    expect((fixture.nativeElement as HTMLElement).querySelectorAll('.card').length).toBe(1);
    expect(subscribeButton(fixture).disabled).toBe(false);
    expect(close).not.toHaveBeenCalled();
  });
```

Replace `'renders the tag picker and sends the checked tag ids on submit'` with:

```ts
  it('discovers with the address alone and subscribes the card with the checked tags', () => {
    const fixture = create();
    expect((fixture.nativeElement as HTMLElement).querySelector('app-tag-picker')).not.toBeNull();

    fixture.componentInstance.toggleTag(2);
    fixture.componentInstance.form.setValue({ url: 'https://example.com/feed' });
    fixture.componentInstance.submit();

    const discover = ctrl.expectOne('https://api.test/api/feeds/discover');
    expect(discover.request.body).toEqual({ url: 'https://example.com/feed' });
    discover.flush({
      candidates: [
        { url: 'https://example.com/feed', title: 'Feed', format: 'rss', preview: RSS_PREVIEW },
      ],
    });
    fixture.detectChanges();

    subscribeButton(fixture).click();
    const subscribe = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subscribe.request.body).toEqual({ url: 'https://example.com/feed', tagIds: [2] });
    subscribe.flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });
```

Replace `'tells the user how to subscribe a Bluesky profile hidden from logged-out readers'` with:

```ts
  it('offers a Bluesky profile hidden from logged-out readers without a preview, and says why', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({
      url: 'https://bsky.app/profile/rusty-17.bsky.social',
    });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [
        {
          url: 'https://bsky.app/profile/rusty-17.bsky.social',
          title: 'Rusty',
          format: 'bluesky-api',
          noPreviewReason: 'hidden_profile',
        },
      ],
    });
    fixture.detectChanges();

    ctrl.expectNone((request) => request.url.endsWith('/api/feeds/preview'));
    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.querySelector('.badge.format')?.textContent?.trim()).toBe('Bluesky');
    expect(card.querySelector('.no-preview')?.textContent).toContain('logged-in Bluesky users');
    expect(card.querySelector('.preview-toggle')).toBeNull();

    (card.querySelector('.subscribe') as HTMLButtonElement).click();
    const subscribe = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subscribe.request.body).toEqual({ url: 'https://bsky.app/profile/rusty-17.bsky.social' });
    subscribe.flush(
      {
        type: 'bluesky_profile_hidden',
        title: 'Profile hidden from logged-out readers',
        status: 422,
        detail:
          'This profile is only visible to logged-in Bluesky users. Connect a Bluesky account in Settings → Social Media to subscribe.',
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    fixture.detectChanges();

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('app-field .error')?.textContent,
    ).toContain('Connect a Bluesky account in Settings → Social Media');
    expect(close).not.toHaveBeenCalled();
  });

  it('tells the user up front to connect a Bluesky account for a hidden profile', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({
      url: 'https://bsky.app/profile/rusty-17.bsky.social',
    });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [
        {
          url: 'https://bsky.app/profile/rusty-17.bsky.social',
          title: 'Rusty',
          format: 'bluesky-api',
          noPreviewReason: 'connect_bluesky_account',
        },
      ],
    });
    fixture.detectChanges();

    ctrl.expectNone((request) => request.url.endsWith('/api/feeds/preview'));
    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.querySelector('.no-preview')?.textContent).toContain(
      'Connect a Bluesky account in Settings → Social Media',
    );
    expect(card.querySelector('.preview-toggle')).toBeNull();
  });

  it('words a no-preview reason it does not know generically', () => {
    expect(create().componentInstance.noPreviewText('quantum_flux')).toBe(
      'No preview is available for this feed.',
    );
  });
```

Replace `'fills in a URL it was opened with and waits for Add, so tags can be picked first'` with:

```ts
  it('fills in a URL it was opened with and waits for Add, so tags can be picked first', () => {
    dialogData = { url: 'https://dropped.example/' };
    const fixture = create();

    expect(fixture.componentInstance.form.getRawValue().url).toBe('https://dropped.example/');
    ctrl.expectNone('https://api.test/api/feeds/discover');

    fixture.componentInstance.toggleTag(2);
    fixture.componentInstance.submit();
    const discover = ctrl.expectOne('https://api.test/api/feeds/discover');
    expect(discover.request.body).toEqual({ url: 'https://dropped.example/' });
    discover.flush({
      candidates: [
        { url: 'https://dropped.example/feed', title: 'Dropped', format: 'rss', preview: RSS_PREVIEW },
      ],
    });
    fixture.detectChanges();

    subscribeButton(fixture).click();
    const request = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(request.request.body).toEqual({ url: 'https://dropped.example/feed', tagIds: [2] });
    request.flush({ subscription: { id: 4 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 4 });
  });
```

In every other test below, the search request is now discover. In each, change the `ctrl.expectOne('https://api.test/api/subscriptions')` that directly follows `fixture.componentInstance.submit()` to `ctrl.expectOne('https://api.test/api/feeds/discover')`; an `expectOne('https://api.test/api/subscriptions')` that follows a `.subscribe` click stays as it is. The tests: `'reports that a search is running while the subscribe is in flight'` (rename to `'reports that a search is running while discover is in flight'`), `'carries the selected tags through to a picked candidate'`, `'auto-expands and previews a single candidate'`, `'auto-previews the first candidate and previews the rest lazily on click'`, `'labels a scraped candidate and subscribes/previews with the scraped format'`, `'labels a Mixcloud candidate and subscribes and previews with its format and title'`, `'detects the dialog dropping the selected WordPress title when it subscribes'`, `'marks a scraped candidate as experimental'`, `'warns when the site blocks scraping and hides the footer submit'`, `'tells the user to wait when the site is rationing requests'`, `'says the feed is too large rather than unreachable'`, `'shows a generic warning for a scrape-failure reason it does not recognise'`, `'clears the scrape-failure warning once the URL is edited'`, `'drops stale candidate cards when a re-search then fails to scrape'` (both of its searches), `"shows the backend's problem detail when a preview fails"`, `'falls back to a generic line when a failed preview carries no detail'`, `'shows an empty state when no candidates are found'`, `'says nothing about scraping when scraping is off and nothing was found'`, `'shows a field error on 422'`. Their bodies and assertions are otherwise unchanged.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-api.spec.ts src/app/reader/feeds/add-feed`
Expected: FAIL — `api.discoverFeeds is not a function`, `Expected one matching request for criteria "Match URL: https://api.test/api/feeds/discover", found none`.

- [ ] **Step 3: Models and API**

`models.ts` — `FeedCandidate`, `SubscribeResult` and the new types become:

```ts
export type NoPreviewReason = 'hidden_profile' | 'connect_bluesky_account' | (string & {});

export interface FeedCandidate {
  url: string;
  title: string | null;
  /** 'rss', 'atom' or 'feed', else a source format such as 'scraped' or 'bluesky-api'; an open set. */
  format: string;
  /** Discovery already read this feed, so the card needs no preview request. */
  preview?: FeedPreview;
  noPreviewReason?: NoPreviewReason;
}
```

```ts
/** POST /feeds/discover; an empty list may carry the reason the scraper fallback gave up. */
export interface DiscoverResult {
  candidates: FeedCandidate[];
  scrapeFailureReason?: ScrapeFailureReason;
}

export interface SubscribeResult {
  subscription: SubscriptionDto;
}
```

(Replace the old `SubscribeResult` union and its comment.)

`reader-api.ts`: import `DiscoverResult` from `./models`, and after `subscribe()` add:

```ts
  discoverFeeds(url: string): Observable<DiscoverResult> {
    return this.http.post<DiscoverResult>(`${this.base}/api/feeds/discover`, { url });
  }
```

- [ ] **Step 4: The dialog**

`add-feed-dialog.component.ts`:

- `models` import becomes `import { FeedCandidate, FeedPreview, NoPreviewReason, ScrapeFailureReason, SubscriptionDto } from '../../models';`.
- After `FAILURE_TEXT_KEYS` add:

```ts
const NO_PREVIEW_TEXT_KEYS = new Map<NoPreviewReason, string>([
  ['hidden_profile', 'noPreviewHiddenProfile'],
  ['connect_bluesky_account', 'noPreviewConnectBluesky'],
]);
```

- `FORMAT_LABELS` gains `['bluesky-api', 'Bluesky'],`.
- After `readonly expanded = …` add `readonly subscribing = signal(false);`.
- Below the class-level constants (outside the class), add:

```ts
function inlinePreviews(candidates: FeedCandidate[]): Record<string, PreviewState> {
  const previews: Record<string, PreviewState> = {};
  for (const candidate of candidates) {
    if (candidate.preview) previews[candidate.url] = { status: 'ok', preview: candidate.preview };
  }
  return previews;
}
```

- Add after `failureText()`:

```ts
  noPreviewText(reason: NoPreviewReason): string {
    const key = NO_PREVIEW_TEXT_KEYS.get(reason) ?? 'noPreviewGeneric';
    return this.i18n.translate(`dialog.addFeed.${key}`);
  }
```

- `submit()`, `pick()` and the private `subscribe()` are replaced by:

```ts
  submit(): void {
    if (this.form.invalid) return;
    this.discover(this.form.getRawValue().url);
  }

  pick(candidate: FeedCandidate): void {
    const format = this.storedFormat(candidate);
    const passesTitle = format !== undefined && format !== 'scraped';
    this.subscribing.set(true);
    this.error.set(null);
    this.api
      .subscribe({
        url: candidate.url,
        format,
        tagIds: [...this.checked()],
        title: passesTitle ? (candidate.title ?? undefined) : undefined,
      })
      .subscribe({
        next: (response) => this.ref.close(response.subscription),
        error: (error: HttpErrorResponse) => {
          this.subscribing.set(false);
          this.showError(error);
        },
      });
  }
```

and, with the other private methods:

```ts
  private discover(url: string): void {
    this.loading.set(true);
    this.error.set(null);
    this.searched.set(false);
    this.failureReason.set(null);
    // Start every search visually clean — a previous search's cards must not
    // linger above a new result, least of all above a scrape-failure warning.
    this.candidates.set([]);
    this.previews.set({});
    this.expanded.set(null);
    this.api.discoverFeeds(url).subscribe({
      next: (response) => {
        this.loading.set(false);
        if (response.scrapeFailureReason) {
          this.failureReason.set(response.scrapeFailureReason);
          return;
        }
        this.showCandidates(response.candidates);
      },
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);
        this.showError(error);
      },
    });
  }

  /** Opens the first card so a preview is always in view: discovery orders native feeds first. */
  private showCandidates(candidates: FeedCandidate[]): void {
    this.previews.set(inlinePreviews(candidates));
    this.candidates.set(candidates);
    this.searched.set(true);
    const first = candidates[0];
    if (first && !first.noPreviewReason) this.toggle(first);
  }

  private showError(error: HttpErrorResponse): void {
    const problem = parseProblem(error);
    this.error.set(problem.errors?.['url']?.[0] ?? problem.detail ?? problem.title);
  }
```

`ensurePreview()` is unchanged: it returns early for a URL already in `previews()`, which an inline preview now is.

`add-feed-dialog.component.html`, inside the card:

- wrap the `<button type="button" class="preview-toggle" …>…</button>` in `@if (!c.noPreviewReason) { … }`;
- after the `@if (c.format === 'scraped') { <p class="muted scraped-hint">…</p> }` block add:

```html
              @if (c.noPreviewReason; as reason) {
                <p class="muted no-preview">{{ noPreviewText(reason) }}</p>
              }
```

- the card's Subscribe button becomes:

```html
              <button
                type="button"
                class="subscribe primary"
                [disabled]="subscribing()"
                (click)="pick(c)"
              >
                {{ 'dialog.addFeed.subscribe' | transloco }}
              </button>
```

- [ ] **Step 5: Strings, every locale**

`frontend/public/i18n/en.json`, `dialog.addFeed`, after `"previewUnavailable"`:

```json
    "noPreviewHiddenProfile": "Only logged-in Bluesky users can see this profile, so there is no preview. Subscribing reads it through your connected Bluesky account.",
    "noPreviewConnectBluesky": "Only logged-in Bluesky users can see this profile, so there is no preview. Connect a Bluesky account in Settings → Social Media to subscribe.",
    "noPreviewGeneric": "No preview is available for this feed.",
```

`frontend/public/i18n/de.json`, same place:

```json
    "noPreviewHiddenProfile": "Dieses Profil sehen nur angemeldete Bluesky-Nutzer, daher gibt es keine Vorschau. Abonniert wird es über dein verbundenes Bluesky-Konto.",
    "noPreviewConnectBluesky": "Dieses Profil sehen nur angemeldete Bluesky-Nutzer, daher gibt es keine Vorschau. Verbinde unter Einstellungen → Social Media ein Bluesky-Konto, um es zu abonnieren.",
    "noPreviewGeneric": "Für diesen Feed gibt es keine Vorschau.",
```

- [ ] **Step 6: The user-facing feature line**

`docs/features.md` lines 78–79 become:

```markdown
- Preview every feed before you subscribe: Add shows the feeds it finds at an
  address with their recent items, whether entries carry images, and whether
  the feed delivers full text or only summaries.
```

- [ ] **Step 7: Run the tests to verify they pass, then the gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-api.spec.ts src/app/reader/feeds/add-feed`
Expected: PASS.
Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc (app, spec, e2e) and Jest all green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts \
  frontend/src/app/reader/feeds/add-feed frontend/public/i18n/en.json frontend/public/i18n/de.json docs/features.md
git commit -m "feat(#2182): the add-feed dialog discovers on Add and subscribes from a card"
```

---

### Task 5: Playwright smokes stub discover

**Files:**
- Modify: `frontend/e2e/feed-directory.spec.ts`
- Modify: `frontend/e2e/add-feed-mobile.spec.ts:21-32`

**Interfaces:**
- Consumes (Task 4): Add posts `POST /api/feeds/discover`; a card's `.subscribe` posts `POST /api/subscriptions`; a candidate's `preview` renders `app-preview-entry-row`s without `POST /api/feeds/preview`.

- [ ] **Step 1: Rewrite the feed-directory stub and test**

In `feed-directory.spec.ts`, add after `GERMANY`:

```ts
const ALPHA_PREVIEW = {
  title: 'Alpha fixture feed',
  itemCount: 1,
  content: 'summary',
  hasImages: false,
  items: [
    {
      title: 'Alpha headline',
      url: 'https://alpha.fixtures.invalid/1',
      author: null,
      summary: 'A short snippet.',
      imageUrl: null,
      imageWidth: null,
      imageHeight: null,
      publishedAt: '2026-11-01T10:00:00+00:00',
    },
  ],
};
```

`stubFeedDirectory` returns `Promise<{ discoverPosts: string[]; previewPosts: string[]; subscribePosts: string[] }>`, declares `const discoverPosts: string[] = [];` and `const previewPosts: string[] = [];` next to `subscribePosts`, and before the `/api/subscriptions` route adds:

```ts
  await page.route('**/api/feeds/discover', (route) => {
    discoverPosts.push((route.request().postDataJSON() as { url: string }).url);
    return route.fulfill({
      json: {
        candidates: [
          { url: ALPHA_FEED, title: 'Alpha fixture feed', format: 'rss', preview: ALPHA_PREVIEW },
        ],
      },
    });
  });
  await page.route('**/api/feeds/preview', (route) => {
    previewPosts.push((route.request().postDataJSON() as { url: string }).url);
    return route.fulfill({ status: 500 });
  });
```

and ends with `return { discoverPosts, previewPosts, subscribePosts };`. Its docblock's "the subscription POST the add-feed dialog sends on Add" becomes "the discover and subscription POSTs the add-feed dialog sends". The test destructures `const { discoverPosts, previewPosts, subscribePosts } = await stubFeedDirectory(page);`, and its last two statements are replaced by:

```ts
  await dialog.locator('button[type=submit]').click();
  await expect.poll(() => discoverPosts).toEqual([ALPHA_FEED]);
  await expect(dialog.locator('app-preview-entry-row')).toHaveCount(1);
  expect(subscribePosts).toEqual([]);

  await dialog.locator('.card .subscribe').click();
  await expect.poll(() => subscribePosts).toEqual([ALPHA_FEED]);
  expect(previewPosts).toEqual([]);
```

(The `.card .subscribe` locator, not a role name: the seeded admin may run the UI in German.)

- [ ] **Step 2: Rewrite the mobile stub**

In `add-feed-mobile.spec.ts`, `stubDiscovery()`'s `/api/subscriptions` route becomes two routes:

```ts
  await page.route('**/api/feeds/discover', (route) =>
    route.fulfill({ status: 200, json: { candidates: CANDIDATES } }),
  );
  await page.route('**/api/subscriptions', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    await route.fulfill({ status: 201, json: { subscription: { id: 4242, title: 'News only' } } });
  });
```

The `/api/feeds/preview` route stays (these candidates carry no inline preview). The docblock's "so the two feed endpoints are stubbed" becomes "so the feed endpoints are stubbed".

- [ ] **Step 3: Run the smokes against this checkout's stack**

Run: `docker compose up -d && docker compose exec php bin/console cache:clear && docker compose restart worker`, then `cd frontend && npm run e2e -- e2e/feed-directory.spec.ts e2e/add-feed-mobile.spec.ts`
Expected: 3 passed. (A skip for "seeded admin login unavailable" is not a pass: run `docker compose exec php bin/console app:e2e:seed-admin` and rerun.)

- [ ] **Step 4: Lint and commit**

Run: `docker compose exec -T frontend npm run check`
Expected: green (it type-checks `e2e/`).

```bash
git add frontend/e2e/feed-directory.spec.ts frontend/e2e/add-feed-mobile.spec.ts
git commit -m "test(#2182): the add-feed smokes stub discover and subscribe from the card"
```

---

### Task 6: Subscribe only subscribes; a non-feed is a 422 pointing at discover

**Files:**
- Create: `backend/src/Service/Subscription/Exception/NotAFeedException.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SubscriptionProblems.php`
- Modify: `backend/src/Service/Subscription/Model/SubscribeOutcomeModel.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php:72-102`
- Modify: `backend/src/Controller/Api/SubscriptionController.php:17,75-90`
- Delete: `backend/src/Http/SubscribeOutcomeJson.php`, `backend/tests/Http/SubscribeOutcomeJsonTest.php`
- Modify (test migration — every test that posted a page to `/api/subscriptions`, asserted a `candidates` answer, or called `subscribe()` expecting candidates or a nullable subscription):
  - `backend/tests/Controller/Api/SubscriptionControllerTest.php` — lines 348–483 (`testSubscribeToHtmlReturnsCandidates`, `testSubscribeToFeedlessPageOffersScrapedCandidate`, `testAFeedlessPageOffersNoScrapedCandidateWhenTheFallbackIsDisabled`, `testBlockedSiteReportsReasonWithEmptyCandidates`) already live on as `FeedPreviewDiscoverTest` (Task 2); here they are replaced by one 422 test
  - `backend/tests/Service/Subscription/SubscriptionServiceTest.php`
  - `backend/tests/Service/Social/HiddenProfileSubscribeRouteTest.php:51,65`
  - `backend/tests/Http/Problem/ProblemContractTest.php` (after `'already subscribed'`)
  - `backend/tests/E2e/ReaderJourneyE2eTest.php:42-53,366,372-375`
- Modify: `docs/architecture.md` (§6, after "Media kinds")

Checked and needing no change: `SubscriptionHiddenProfileTest` (posts a hidden profile, still routed), `PrivateFeedUrlRefusalTest` (validation 422), and `EntryControllerTest`, `SubscriptionSocialListTest`, `MoveFeedToTagTest`, `OpmlControllerTest`, `ReorderTest`, `SubscriptionTrendingCountsTest` (they GET `/api/subscriptions` or post feed URLs).

**Interfaces:**
- Consumes (Task 2): `App\Tests\Support\RecordingFeedDiscovery`.
- Produces: `App\Service\Subscription\Exception\NotAFeedException`; problem `{type: 'not_a_feed', title: 'Not a feed', status: 422, detail: 'No feed could be read at that address. POST it to /api/feeds/discover for the feeds it offers.'}`; `SubscribeOutcomeModel::$subscription: Subscription` (non-null), `SubscribeOutcomeModel::subscribed(Subscription, int = 0)` only; `POST /api/subscriptions` answers only `201 {subscription}` or a problem.

- [ ] **Step 1: Write the failing tests**

`SubscriptionControllerTest`: delete lines 348–483 (the four tests named above) and add in their place:

```php
    public function testSubscribingAPageIsRefusedWithAPointerToDiscover(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('html@example.com'));
        $html = /** @lang TEXT */ '<!doctype html><html><head>'
            . '<link rel="alternate" type="application/rss+xml" href="/rss.xml">'
            . '</head><body>x</body></html>';
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://example.com/blog',
            FetchResponseModel::fetched(
                'https://example.com/blog/',
                permanentRedirect: false,
                body: $html,
                etag: null,
                lastModified: null,
            ),
        );
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://example.com/blog'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = $this->payload($client);
        self::assertSame('not_a_feed', $problem['type'] ?? null);
        $detail = $problem['detail'] ?? null;
        self::assertIsString($detail);
        self::assertStringContainsString('/api/feeds/discover', $detail);
        self::assertSame(0, $this->entityManager()->getRepository(Subscription::class)->count([]));
    }
```

After that deletion `scrapedFixture()` is no longer called in the file: delete `use ScrapedFixtures;` and the `App\Tests\Service\Scraper\ScrapedFixtures` import. Add `use Symfony\Component\HttpFoundation\Response;`.

`SubscriptionServiceTest` (imports `App\Service\Subscription\Exception\NotAFeedException`, `App\Tests\Support\RecordingFeedDiscovery`; `StoredUserSettings` is already imported):

- Replace `testHtmlPageReturnsCandidatesWithoutSubscribing` with:

```php
    public function testAPageIsRefusedAsNotAFeed(): void
    {
        $user = $this->factory()->create('cand@example.com');
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([
            new FeedCandidateModel('https://example.com/rss.xml', 'Main', 'rss'),
        ])));

        $this->expectException(NotAFeedException::class);
        $service->subscribe($user, 'https://example.com/blog');
    }

    public function testAnUnreachableAddressIsRefusedAsNotAFeed(): void
    {
        $user = $this->factory()->create('unreachable@example.com');
        $service = $this->service($this->discoveryReturning(
            FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Unreachable),
        ));

        $this->expectException(NotAFeedException::class);
        $service->subscribe($user, 'https://gone.example.com/feed');
    }

    public function testSubscribeNeverAsksDiscoveryForAScrapedFallback(): void
    {
        $user = $this->factory()->create('no-scrape-ask@example.com');
        StoredUserSettings::reading($this->entityManager, $user)->setScrapeFallbackEnabled(true);
        $this->entityManager->flush();
        $discovery = new RecordingFeedDiscovery($this->discovered('https://example.com/feed.xml'));

        $this->service($discovery)->subscribe($user, 'https://example.com/feed');

        self::assertSame([ScrapeFallback::Disabled], $discovery->fallbacks);
    }
```

  (import `App\Service\Discovery\Model\ScrapeFailureReason`).
- In `testDirectFeedCreatesFeedAndSubscription`, delete `self::assertNotNull($outcome->subscription);` and `self::assertSame([], $outcome->candidates);`.
- Line 161: `->subscription?->getFeed()` becomes `->subscription->getFeed()`; delete line 163 `self::assertNotNull($feed);`.
- Delete every remaining `self::assertNotNull($outcome->subscription);` (lines 221, 272, 291, 313, 338, 351, 365, 387, 405, 423, 444, 530, 553 before these edits).
- Line 519: `$outcome->subscription?->getFeed()` becomes `$outcome->subscription->getFeed()`.
- In `testARouteThatTakesTheUrlAnswersInsteadOfDiscovery`, `$taken = SubscribeOutcomeModel::candidates([]);` becomes:

```php
        $taken = SubscribeOutcomeModel::subscribed(new Subscription(
            $user,
            new Feed('https://bsky.app/profile/rusty-17.bsky.social'),
            new \DateTimeImmutable('2026-06-01T00:00:00Z'),
        ));
```

- In `testAVerbatimFormatNeverAsksTheRoutes`, `new RecordingSubscribeRoute(SubscribeOutcomeModel::candidates([]))` becomes `new RecordingSubscribeRoute(null)` (the route is never asked, which the test asserts).

`HiddenProfileSubscribeRouteTest` lines 51 and 65: `$outcome?->subscription?->getFeed()->getUrl()` becomes `$outcome?->subscription->getFeed()->getUrl()`.

`ProblemContractTest` (import `App\Service\Subscription\Exception\NotAFeedException`), after the `'already subscribed'` yield:

```php
        yield 'not a feed' => [
            new NotAFeedException(),
            [
                'type' => 'not_a_feed',
                'title' => 'Not a feed',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'detail' => 'No feed could be read at that address. POST it to /api/feeds/discover for the feeds it offers.',
            ],
        ];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SubscriptionControllerTest.php tests/Service/Subscription/SubscriptionServiceTest.php tests/Http/Problem/ProblemContractTest.php`
Expected: FAIL — `Class "App\Service\Subscription\Exception\NotAFeedException" not found`.

- [ ] **Step 3: The exception and its problem**

`backend/src/Service/Subscription/Exception/NotAFeedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Exception;

final class NotAFeedException extends \RuntimeException
{
}
```

`SubscriptionProblems::resolve()` gains an arm (import `App\Service\Subscription\Exception\NotAFeedException`):

```php
            $exception instanceof NotAFeedException => new ResolvedProblem(new ApiProblem(
                'not_a_feed',
                'Not a feed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'No feed could be read at that address. POST it to /api/feeds/discover for the feeds it offers.',
            )),
```

- [ ] **Step 4: Subscribe stops offering candidates**

`SubscribeOutcomeModel` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

use App\Entity\Subscription;

final readonly class SubscribeOutcomeModel
{
    private function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public Subscription $subscription,
        public int $unreadCount,
    ) {
    }

    public static function subscribed(Subscription $subscription, int $unreadCount = 0): self
    {
        return new self($subscription, $unreadCount);
    }
}
```

`SubscriptionService::subscribe()` — its docblock and the discovery tail become (import `App\Service\Discovery\Model\ScrapeFallback` and `App\Service\Subscription\Exception\NotAFeedException`):

```php
    /**
     * @param list<Tag> $tags the user-owned tags to attach to the new subscription
     *
     * @throws NotAFeedException when the address serves no feed: a page, or nothing at all
     */
    public function subscribe(
        User $user,
        string $url,
        ?string $format = null,
        array $tags = [],
        ?string $initialTitle = null,
    ): SubscribeOutcomeModel {
        if (SourceFormat::isVerbatim($format)) {
            return $this->subscribeVerbatim($user, $url, $format, $tags, $initialTitle);
        }

        $routed = $this->routed(new SubscribeRequestModel($user, $url, $tags));
        if (null !== $routed) {
            return $routed;
        }

        $discovered = $this->discovery->discover($url, ScrapeFallback::Disabled)->feed
            ?? throw new NotAFeedException();
        $subscription = $this->creator->create($user, $discovered->url, SourceFormat::XML, $tags);
        $unread = $this->firstFetch->record($subscription->getFeed(), $discovered);

        return SubscribeOutcomeModel::subscribed($subscription, $unread);
    }
```

`ScrapeFallbackPolicy` stays injected: `subscribeVerbatim()` still calls `assertMayScrape()`.

`SubscriptionController::create()` becomes (drop the `App\Http\SubscribeOutcomeJson` import):

```php
    #[Route('', name: 'api_subscriptions_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] SubscribeRequest $request): JsonResponse
    {
        $tags = $this->tags->findAllByIdsForUser($user->requireId(), $request->tagIds);
        $outcome = $this->subscriber->subscribe($user, $request->url, $request->format, $tags, $request->title);

        return new JsonResponse(
            ['subscription' => SubscriptionJson::one($outcome->subscription, $outcome->unreadCount)],
            Response::HTTP_CREATED,
        );
    }
```

Delete the now unused mapper and its test:

```bash
git rm backend/src/Http/SubscribeOutcomeJson.php backend/tests/Http/SubscribeOutcomeJsonTest.php
```

- [ ] **Step 5: The black-box e2e discovers instead of subscribing a homepage**

In `backend/tests/E2e/ReaderJourneyE2eTest.php` (add `use Symfony\Component\HttpFoundation\Response;`):

- `testHomepageFeedDiscovery()`'s docblock first sentence becomes "Discovering a homepage answers 200 with well-formed candidates: …"; its request and status check become:

```php
        $response = $this->postJson('/api/feeds/discover', ['url' => $homepage], $this->adminJwt());
        $status = $response->getStatusCode();

        self::assertSame(Response::HTTP_OK, $status, $label . ' homepage discovery should return 200 candidates');
```

- In `discoverFeedCandidates()`, `$this->postJson('/api/subscriptions', ['url' => $homepage], $token)` becomes `$this->postJson('/api/feeds/discover', ['url' => $homepage], $token)`, and the comment above `$format = …` becomes:

```php
                    // Carry the format: a 'scraped' candidate's URL is the homepage
                    // itself, so re-posting it without the format is refused as
                    // not a feed (422) instead of subscribing (201).
```

The three discovers per homepage stay far inside the 60-per-5-minutes `feed_discover` budget.

- [ ] **Step 6: Document the two-step contract for native clients**

`docs/architecture.md`, a new subsection at the end of §6, after the "Media kinds" table:

```markdown
### Adding a feed

Adding a feed takes two requests, so a client can show what it found before anything is subscribed (#2182).

1. `POST /api/feeds/discover` with `{"url": …}` (a bare host gets `https://`) looks the address up and never
   subscribes. It answers `200` with `candidates`, each `{url, title, format}`. A feed discovery has already read
   carries its `preview`, the object `POST /api/feeds/preview` answers under `feed`. A candidate that cannot be
   previewed carries `noPreviewReason`: `hidden_profile` for a Bluesky profile only logged-in users see, which the
   user's connected Bluesky account reads, or `connect_bluesky_account` for such a profile when the user has no ready
   Bluesky account (subscribing it then answers `422 bluesky_profile_hidden`). An empty list may carry
   `scrapeFailureReason`. Limited per user (`feed_discover`).
2. `POST /api/subscriptions` with the picked candidate's `url`, its `format` when that is `scraped`, `wp-json` or
   `mixcloud-api`, and optional `title` and `tagIds`, answers `201` with the `subscription`. An address that serves no
   feed is a `422` problem of type `not_a_feed`; this endpoint never answers with candidates.

A candidate without `preview` or `noPreviewReason` is previewed on demand with `POST /api/feeds/preview`. Decode
`format` and `noPreviewReason` leniently: new values are added.
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api tests/Service/Subscription tests/Service/Social tests/Http`
Expected: PASS.

- [ ] **Step 8: Full verification**

Run, in parallel:
- `cd backend && composer test:parallel` — Expected: OK (SQLite leg).
- `docker compose exec php composer test:parallel` — Expected: OK (MySQL leg).

Then:
- `cd backend && composer check && composer md` — Expected: clean; every `src` file this branch touched is PHPMD-clean.
- `cd backend && composer infection:diff` — Expected: MSI at or above `minMsi` (80) over this branch's files; an escaped mutant gets a test, never an ignore.
- `docker compose exec php bin/console cache:clear && docker compose restart worker`, then `cd backend && composer e2e -- --filter ReaderJourneyE2eTest` — Expected: passes or skips per unreachable site, never fails.
- `cd frontend && npm run e2e -- e2e/feed-directory.spec.ts e2e/add-feed-mobile.spec.ts` — Expected: 3 passed.
- `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` — Expected: no new deprecation or error.
- Live check on http://localhost:4200: Add a feed URL (one card, preview shown at once, no `/api/feeds/preview` request in the network panel), a site URL (several cards, lazy previews), and `https://bsky.app/profile/<a hidden profile>` with and without a connected Bluesky account (no-preview card; with the account it says Subscribe reads it through that account and Subscribe works; without one the card already says to connect a Bluesky account).

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Subscription backend/src/Http/Problem/ExceptionProblems/SubscriptionProblems.php \
  backend/src/Controller/Api/SubscriptionController.php backend/tests/Controller/Api/SubscriptionControllerTest.php \
  backend/tests/Service/Subscription/SubscriptionServiceTest.php backend/tests/Service/Social/HiddenProfileSubscribeRouteTest.php \
  backend/tests/Http/Problem/ProblemContractTest.php backend/tests/E2e/ReaderJourneyE2eTest.php docs/architecture.md
git commit -m "fix(#2182): subscribe only subscribes; a URL that is not a feed is a 422 pointing at discover"
```

---

## Open questions

None.
