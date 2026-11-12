# #2182 /simplify follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the /simplify suggestions Lars approved for the #2182 branch (1, 3, 4, 5, 6, 9; 2 is done, 8 needs no code), so the add-feed dialog and discovery stop encoding the same facts twice.

**Architecture:** Backend: a throttled site becomes a Discovery exception mapped once to `feed_throttled`, and each discover candidate carries its display `label` and the `storedFormat` subscribe must send. Frontend: the shared overlay panel gains a `[footerStart]` slot, `core` gains a translated problem lookup with convention keys, and the add-feed dialog drops its private tables, its second busy flag and its hand-rolled set toggle.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (PHPUnit 12, PHPStan max, PHPMD, Infection), Angular 20 standalone + signals, Transloco 8.4, Jest in Docker.

**Spec:** Issue #2182 (design and Lars's decisions are in the issue body and comments) plus the approved suggestion list recorded in `.superpowers/sdd/2026-11-12-2182-preview-before-subscribe/progress.md` (line "Lars (chat): implement skipped /simplify suggestions 1,2,3,4,5,6,8,9 (not 7)"). The suggestions, as approved:

1. One busy flag in the dialog: discovery and subscribing disable Preview and every card's Subscribe together (behaviour change approved).
2. ~~`HttpsImageUrl::orNull()` in the preview~~ — **done**, commit 6e51a54f4.
3. Shared dialog footer with a start and an end area in `src/app/shared`; the add-feed dialog drops its footer CSS tricks.
4. One shared "problem type → translated text" lookup in `core/problem`, with convention keys; the dialog's code → text tables go.
5. The backend sends each candidate's format label and storage format; the SPA's two format tables go.
6. Discovery raises "throttled" as an exception; subscribe stops turning a result field into an exception.
7. (not approved — out of scope)
8. No second Bluesky hidden-profile lookup on subscribe — **already true, no code** (see Ruling R1).
9. Shorter `toggleTag` and `passesTitle`.

## Global Constraints

- Branch `fix/2182-preview-before-subscribe`, work in place (no worktree), never push, never merge, never touch `develop`.
- Commit format `refactor(#2182): <what is now true>`; no Claude attribution of any kind.
- Backend: CLAUDE.md "PHP code style" is binding — `final readonly`, intent-revealing names, no `$e`, guard clauses, `Response::HTTP_*` never a bare status, no new comment unless a future reader would get the code wrong without it, every touched `src` file PHPMD-clean, no PHPStan ignores or baseline entries.
- Domain code knows no HTTP: the problem mapping lives only in `src/Http/Problem/ExceptionProblems/*Problems`.
- The discover endpoint's JSON for a throttled site stays exactly `{"candidates": [], "scrapeFailureReason": "throttled"}`; `POST /api/subscriptions` for a throttled site stays `503` with type `feed_throttled`, title `Site is rationing requests`, detail `The site asked us to slow down. Try again in a minute.`
- Frontend: component styles in the sibling `.scss`; no hex colours or ad-hoc `px` outside the theme; run Jest only inside Docker (`docker compose exec -T frontend npm test -- <pattern>`); `npm run check` is the gate.
- Every user-facing string exists in both `frontend/public/i18n/en.json` and `de.json` (the `i18n-dictionaries.spec.ts` parity test enforces it).

## Rulings (made while planning)

- **R1 — Item 8 needs no code.** `TolerantHiddenProfiles` depends on `HiddenProfilesInterface`, which the container resolves to `CachedHiddenProfiles` (`#[AsDecorator]` over `AppViewHiddenProfiles`, verified with `bin/console debug:container 'App\Service\Social\HiddenProfiles\HiddenProfilesInterface'`). It remembers both verdicts in `social.profile_visibility.cache` for a day (#1667) and never stores a failed lookup, because the inner call throws before `remember()`. Discover's lookup therefore answers subscribe's. A second cache would duplicate it. Cost if wrong: one extra AppView call per hidden-profile subscribe.
- **R2 — Item 4 scope.** The lookup is shared and lives in `core`. The add-feed and composer dialogs use it (they are the dialogs that show a problem's text). Services and the toast keep `problemMessage()`. The new `problemText()` falls back to `problemMessage()`, so moving them later changes nothing until a `problem.<type>` key exists. Cost if wrong: a later one-line swap per caller.
- **R3 — Item 9 `passesTitle`.** The backend already ignores `title` outside the verbatim path and for `scraped` (`SubscriptionService::subscribeVerbatim`). So the dialog sends the candidate's title whenever it has one and stops re-deciding that rule. Cost if wrong: none on the wire's meaning; the request carries a title the backend drops.
- **R4 — Item 3 migration set.** The two `overlay-panel` consumers that push footer content to the start are the add-feed dialog (browse links) and the edit-subscription dialog (`.unsubscribe { margin-inline-end: auto }`). Both move to `[footerStart]`. `selection-share`'s `.count` is not an overlay-panel footer and stays.
- **R5 — Item 9 boy-scout.** `edit-subscription-dialog.component.ts` `toggle()` hand-rolls the same set toggle as `toggleTag`; it moves to `core/toggled-set.ts` in the same commit.

---

### Task 1: A throttled site is a Discovery exception, mapped once

**Files:**
- Create: `backend/src/Service/Discovery/Exception/SiteThrottledException.php`
- Create: `backend/tests/Support/ThrottledFeedDiscovery.php`
- Create: `backend/tests/Http/Problem/ExceptionProblems/DiscoveryProblemsTest.php`
- Modify: `backend/src/Service/Discovery/FeedDiscovery/FeedDiscovery.php` (class docblock; the `catch (FeedThrottledException)` arm)
- Modify: `backend/src/Service/Discovery/FeedDiscovery/FeedDiscoveryInterface.php`
- Modify: `backend/src/Service/Preview/PreviewedDiscovery.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php` (delete `noFeedFound()`)
- Delete: `backend/src/Service/Subscription/Exception/FeedThrottledOnSubscribeException.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/DiscoveryProblems.php`, `SubscriptionProblems.php`
- Modify tests: `tests/Service/Discovery/FeedDiscovery/FeedDiscoveryTest.php` (`testARationingSiteIsNotAskedSixMoreQuestions`), `tests/Service/Preview/PreviewedDiscoveryTest.php`, `tests/Service/Subscription/SubscriptionServiceTest.php` (`testAThrottledSiteIsReportedAsThrottledNotAsNotAFeed`), `tests/Http/Problem/ExceptionProblems/SubscriptionProblemsTest.php`, `tests/Http/Problem/ProblemContractTest.php`, `tests/Controller/Api/FeedPreviewDiscoverTest.php`
- Unchanged and must stay green: `tests/Controller/Api/SubscriptionControllerTest.php::testSubscribingToAThrottledSiteIsServiceUnavailable`

**Interfaces:**
- Produces: `App\Service\Discovery\Exception\SiteThrottledException` (`final class … extends \RuntimeException`, constructor `(?\Throwable $previous = null)`). `FeedDiscoveryInterface::discover()` documents `@throws SiteThrottledException`.
- `ScrapeFailureReason::Throttled` stays: `PreviewedDiscovery` still reports it.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Support/ThrottledFeedDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Discovery\Exception\SiteThrottledException;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFallback;

final class ThrottledFeedDiscovery implements FeedDiscoveryInterface
{
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
    {
        throw new SiteThrottledException();
    }
}
```

In `FeedDiscoveryTest`, replace the body of `testARationingSiteIsNotAskedSixMoreQuestions` (keep its docblock):

```php
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://www.reddit.com/r/Bitwig/', new FeedThrottledException('x: HTTP 429', 60));

        try {
            $this->discovery($fetcher)->discover('https://www.reddit.com/r/Bitwig/', ScrapeFallback::Enabled);
            self::fail('A rationing site must throw, not report a result.');
        } catch (SiteThrottledException $exception) {
            self::assertInstanceOf(FeedThrottledException::class, $exception->getPrevious());
        }

        self::assertSame(['https://www.reddit.com/r/Bitwig/'], $fetcher->fetchedUrls);
```

Add `use App\Service\Discovery\Exception\SiteThrottledException;`. Drop the `ScrapeFailureReason` import only if nothing else in the file uses it.

In `PreviewedDiscoveryTest`, add after `testAScrapeFailureCarriesItsReason`:

```php
    public function testAThrottledSiteIsAScrapeFailureNotAnError(): void
    {
        $found = $this->service(new ThrottledFeedDiscovery())
            ->discover($this->user('throttled@example.test'), 'https://example.com/');

        self::assertSame([], $found->candidates);
        self::assertSame(ScrapeFailureReason::Throttled, $found->scrapeFailureReason);
    }
```

(import `App\Tests\Support\ThrottledFeedDiscovery`).

In `SubscriptionServiceTest`, replace `testAThrottledSiteIsReportedAsThrottledNotAsNotAFeed` with:

```php
    public function testAThrottledSiteIsReportedAsThrottledNotAsNotAFeed(): void
    {
        $user = $this->factory()->create('throttled@example.com');
        $service = $this->service(new ThrottledFeedDiscovery());

        $this->expectException(SiteThrottledException::class);
        $service->subscribe($user, 'https://slow.example.com/feed');
    }
```

Swap the `FeedThrottledOnSubscribeException` import for `App\Service\Discovery\Exception\SiteThrottledException` and add `App\Tests\Support\ThrottledFeedDiscovery`.

Create `backend/tests/Http/Problem/ExceptionProblems/DiscoveryProblemsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\DiscoveryProblems;
use App\Service\Discovery\Exception\SiteThrottledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class DiscoveryProblemsTest extends TestCase
{
    public function testAThrottledSiteIsServiceUnavailable(): void
    {
        $resolved = (new DiscoveryProblems())->resolve(new SiteThrottledException());

        self::assertNotNull($resolved);
        self::assertSame('feed_throttled', $resolved->problem->type);
        self::assertSame('Site is rationing requests', $resolved->problem->title);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $resolved->problem->status);
        self::assertSame('The site asked us to slow down. Try again in a minute.', $resolved->problem->detail);
    }

    public function testOtherExceptionsPassThrough(): void
    {
        self::assertNull((new DiscoveryProblems())->resolve(new \RuntimeException('x')));
    }
}
```

In `SubscriptionProblemsTest`, delete `testAThrottledSiteIsServiceUnavailable` and its import. In `ProblemContractTest`, change the `'feed throttled on subscribe'` case to `yield 'site throttled' => [new SiteThrottledException(), [...same array...]];` and swap the import.

In `FeedPreviewDiscoverTest`, add after `testABlockedSiteReportsItsReason`:

```php
    public function testAThrottledSiteReportsItsReason(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-throttled@example.com');
        $stub = new StubFeedFetcher();
        $stub->willThrow('https://slow.example.com', new FeedThrottledException('x: HTTP 429', 60));
        $this->installFetcher($stub);

        $body = $this->discover($client, $user, 'https://slow.example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['candidates' => [], 'scrapeFailureReason' => 'throttled'], $body);
    }
```

(import `App\Service\Fetch\Exception\FeedThrottledException`).

- [ ] **Step 2: Run them and see them fail**

Run: `cd backend && php bin/phpunit --filter 'Throttled|Rationing|DiscoveryProblemsTest|ProblemContractTest'`
Expected: errors, because class `SiteThrottledException` does not exist.

- [ ] **Step 3: Implement**

`backend/src/Service/Discovery/Exception/SiteThrottledException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\Exception;

/** The site answered 429: the caller must not ask it again now, and must not read that as "no feed here". */
final class SiteThrottledException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The site asked us to slow down.', 0, $previous);
    }
}
```

`FeedDiscovery::discover()`: the throttled arm becomes

```php
        } catch (FeedThrottledException $exception) {
            // The site has just asked us to slow down; the parallel guesses are
            // the opposite of that, and each would draw its own 429.
            throw new SiteThrottledException($exception);
        }
```

Change the class docblock's last sentence from "Never throws for a bad address: a failure is a scrapeFailureReason, so the subscribe endpoint can always answer." to "A bad address is a scrapeFailureReason, not an exception; only a site that asked us to slow down throws." Add the import, and drop the `ScrapeFailureReason::Throttled` use (other reasons remain).

`FeedDiscoveryInterface`:

```php
interface FeedDiscoveryInterface
{
    /**
     * Never throws for an unreachable or feedless address; with $fallback off, a feedless page yields no reason.
     *
     * @throws SiteThrottledException when the site asked us to slow down
     */
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel;
}
```

`PreviewedDiscovery`: replace the line `$result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));` with `$result = $this->discovered($user, $url);` and add:

```php
    private function discovered(User $user, string $url): FeedDiscoveryResultModel
    {
        try {
            return $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));
        } catch (SiteThrottledException) {
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Throttled);
        }
    }
```

(imports: `SiteThrottledException`, `FeedDiscoveryResultModel`, `ScrapeFailureReason`).

`SubscriptionService::subscribe()`: `$discovered = $result->feed ?? throw new NotAFeedException();`. Delete `noFeedFound()` and the now-unused imports (`FeedDiscoveryResultModel`, `ScrapeFailureReason`, `FeedThrottledOnSubscribeException`). Add to the `subscribe()` docblock: `@throws SiteThrottledException when the site asked us to slow down` (import it).

Delete `src/Service/Subscription/Exception/FeedThrottledOnSubscribeException.php`. Remove its arm and import from `SubscriptionProblems`. Add to `DiscoveryProblems::resolve()`'s `match`:

```php
            $exception instanceof SiteThrottledException => new ResolvedProblem(new ApiProblem(
                'feed_throttled',
                'Site is rationing requests',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'The site asked us to slow down. Try again in a minute.',
            )),
```

Then: `grep -rn "FeedThrottledOnSubscribe" backend docs` must return nothing.

- [ ] **Step 4: Run the tests**

Run: `cd backend && php bin/phpunit --filter 'Throttled|Rationing|DiscoveryProblemsTest|ProblemContractTest|SubscriptionServiceTest|PreviewedDiscoveryTest|FeedPreviewDiscoverTest|SubscriptionControllerTest|FeedDiscoveryTest'`
Expected: all pass, including the unchanged `testSubscribingToAThrottledSiteIsServiceUnavailable`.

- [ ] **Step 5: Lint and commit**

Run: `cd backend && composer cs && composer stan && composer md` (and `mcp__phpstorm__lint_files` on the changed PHP if available). All clean.

```bash
git add -A backend/src backend/tests
git commit -m "refactor(#2182): discovery throws SiteThrottledException for a rationing site, mapped once to feed_throttled"
```

---

### Task 2: Discover candidates carry their label and stored format

**Files:**
- Modify: `backend/src/Http/PreviewedDiscoveryJson.php`
- Modify: `backend/tests/Http/PreviewedDiscoveryJsonTest.php`, `backend/tests/Controller/Api/FeedPreviewDiscoverTest.php`
- Modify: `docs/architecture.md` §6 "Adding a feed" (the candidate shape, line ~164, and step 2, line ~170)

**Interfaces:**
- Produces: each discover candidate JSON gains `label: string` (always) and `storedFormat: string` (present only when `SourceFormat::isVerbatim($format)`, i.e. `scraped`, `wp-json`, `mixcloud-api`). Task 5's SPA reads both.

The label is display text, so it lives in the `src/Http` mapper, not the domain. Mapping (it reproduces the SPA's `formatLabel()` exactly): `rss`→`RSS`, `atom`→`Atom`, `wp-json`→`WordPress`, `mixcloud-api`→`Mixcloud`, `bluesky-api`→`Bluesky`; any other value is capitalised with `ucfirst` (`feed`→`Feed`, `scraped`→`Scraped`); an empty string becomes `Feed`.

- [ ] **Step 1: Write the failing tests**

In `PreviewedDiscoveryJsonTest`, update the two existing expectations to include the new keys. For example, the first becomes:

```php
                ['url' => 'https://example.com/rss', 'title' => 'Example', 'format' => 'rss', 'label' => 'RSS'],
                ['url' => 'https://example.com/atom', 'title' => null, 'format' => 'atom', 'label' => 'Atom'],
```

The preview case gets `'label' => 'RSS'` after `'format' => 'rss'`. Any other test in the file that asserts a whole candidate array is updated the same way: insert `label`, and `storedFormat` for a verbatim format, right after `format`. Then add:

```php
    /** @return iterable<string, array{string, string, ?string}> */
    public static function formats(): iterable
    {
        yield 'rss' => ['rss', 'RSS', null];
        yield 'atom' => ['atom', 'Atom', null];
        yield 'guessed' => [FeedCandidateModel::GUESSED_FORMAT, 'Feed', null];
        yield 'wordpress' => [SourceFormat::WP_JSON, 'WordPress', SourceFormat::WP_JSON];
        yield 'mixcloud' => [SourceFormat::MIXCLOUD_API, 'Mixcloud', SourceFormat::MIXCLOUD_API];
        yield 'scraped' => [SourceFormat::SCRAPED, 'Scraped', SourceFormat::SCRAPED];
        yield 'bluesky' => [SourceFormat::BLUESKY_API, 'Bluesky', null];
        yield 'unknown' => ['podcast', 'Podcast', null];
        yield 'empty' => ['', 'Feed', null];
    }

    #[DataProvider('formats')]
    public function testACandidateCarriesItsLabelAndTheFormatSubscribeMustSend(
        string $format,
        string $label,
        ?string $storedFormat,
    ): void {
        $json = PreviewedDiscoveryJson::of(new PreviewedDiscoveryModel([
            new PreviewedCandidateModel(new FeedCandidateModel('https://example.com/x', null, $format)),
        ]));

        $candidate = $json['candidates'][0];
        self::assertSame($label, $candidate['label']);
        self::assertSame($storedFormat, $candidate['storedFormat'] ?? null);
    }
```

(import `PHPUnit\Framework\Attributes\DataProvider`; `SourceFormat` is already imported. If PHPStan rejects the `$json['candidates'][0]` offset on `array<string, mixed>`, narrow it with `self::assertIsArray()` first, as the controller tests do.)

In `FeedPreviewDiscoverTest::testADirectFeedComesBackAsOneCandidateCarryingItsPreviewAndNothingIsSubscribed`, after `self::assertSame('rss', $candidate['format']);` add:

```php
        self::assertSame('RSS', $candidate['label']);
        self::assertArrayNotHasKey('storedFormat', $candidate);
```

In `testAFeedlessPageOffersAScrapedCandidate`, add assertions that the candidate's `label` is `'Scraped'` and its `storedFormat` is `'scraped'`, reading the candidate the way that test already does.

- [ ] **Step 2: Run them and see them fail**

Run: `cd backend && php bin/phpunit --filter 'PreviewedDiscoveryJsonTest|FeedPreviewDiscoverTest'`
Expected: FAIL. The `label` key is missing.

- [ ] **Step 3: Implement**

In `PreviewedDiscoveryJson`:

```php
    private const array FORMAT_LABELS = [
        'rss' => 'RSS',
        'atom' => 'Atom',
        SourceFormat::WP_JSON => 'WordPress',
        SourceFormat::MIXCLOUD_API => 'Mixcloud',
        SourceFormat::BLUESKY_API => 'Bluesky',
    ];

    /** @return array<string, mixed> */
    private static function candidate(PreviewedCandidateModel $previewed): array
    {
        $candidate = $previewed->candidate;
        $json = [
            'url' => $candidate->url,
            'title' => $candidate->title,
            'format' => $candidate->format,
            'label' => self::labelOf($candidate->format),
        ];
        if (SourceFormat::isVerbatim($candidate->format)) {
            $json['storedFormat'] = $candidate->format;
        }

        if (null !== $previewed->preview) {
            $json['preview'] = FeedPreviewJson::preview($previewed->preview);
        }

        if (null !== $candidate->noPreviewReason) {
            $json['noPreviewReason'] = $candidate->noPreviewReason->value;
        }

        return $json;
    }

    private static function labelOf(string $format): string
    {
        return self::FORMAT_LABELS[$format] ?? ('' === $format ? 'Feed' : ucfirst($format));
    }
```

Use `FeedSyntax::Rss->value` / `FeedSyntax::Atom->value` as the first two keys if a constant expression allows it in a class constant (PHP 8.5 does allow enum `->value` in constant expressions). Prefer that over the literals, matching b75ae992d. Import `App\Enum\SourceFormat` (and `App\Service\Parser\Model\FeedSyntax` if used).

In `docs/architecture.md` §6, the candidate shape sentence becomes "each `{url, title, format, label}`, plus `storedFormat` when subscribe must send one". Step 2 becomes: "`POST /api/subscriptions` with the picked candidate's `url`, and its `storedFormat` as `format` when the candidate carries one". Read both lines first, and keep the rest of each sentence.

- [ ] **Step 4: Run the tests**

Run: `cd backend && php bin/phpunit --filter 'PreviewedDiscoveryJsonTest|FeedPreviewDiscoverTest'`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

Run: `cd backend && composer cs && composer stan && composer md`

```bash
git add backend/src/Http/PreviewedDiscoveryJson.php backend/tests docs/architecture.md
git commit -m "refactor(#2182): discover candidates carry their label and the stored format subscribe must send"
```

---

### Task 3: The overlay panel's footer has a start area

**Files:**
- Modify: `frontend/src/app/shared/overlay-panel/overlay-panel.component.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.html` (footer, lines ~127-134), `.scss` (`.browse-links`, `.browse-links ~ app-button`)
- Modify: `frontend/src/app/reader/feeds/manage/edit-subscription-dialog.component.html` (Unsubscribe button, line ~86), `.scss` (`.unsubscribe`)
- Modify: `docs/design-language.md` (overlay-panel "Content slots" paragraph, line ~603)

**Interfaces:**
- Produces: a `[footerStart]` content slot on `app-overlay-panel`. Its items stack in a column at the footer's start; `[footer]` items stay at the end.

- [ ] **Step 1: Write the failing spec**

In `overlay-panel.component.spec.ts`, extend the host template with `<a footerStart class="start-probe">Browse</a>` and add:

```ts
  it('projects start content into the footer start area, before the end buttons', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    const start = element.querySelector('.footer > .footer-start');
    expect(start?.querySelector('.start-probe')).not.toBeNull();
    expect(element.querySelector('.footer')?.firstElementChild).toBe(start);
  });
```

- [ ] **Step 2: Run it and see it fail**

Run: `docker compose exec -T frontend npm test -- overlay-panel`
Expected: FAIL. `.footer-start` does not exist.

- [ ] **Step 3: Implement the slot**

`overlay-panel.component.html` footer:

```html
  <footer class="footer">
    <div class="footer-start">
      <ng-content select="[footerStart]" />
    </div>
    <ng-content select="[footer]" />
  </footer>
```

`overlay-panel.component.scss`: replace the `.footer`, `.footer > *` and `.footer:empty` rules with:

```scss
.footer {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  align-items: center;
  justify-content: flex-end;
  padding: var(--space-3) var(--space-5) var(--space-5);
}

// Footer buttons keep their own width and take a second line, so a long confirm
// label never squeezes "Cancel" into breaking mid-word.
.footer > * {
  flex: 0 0 auto;
  max-width: 100%;
}

// Zero basis: flex-wrap breaks lines on basis before shrinking, so start content never pushes the buttons down.
.footer > .footer-start {
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
  gap: var(--space-1);
  align-items: flex-start;
}

.footer-start:empty {
  display: none;
}

.footer:not(:has(> :not(.footer-start:empty))) {
  display: none;
}
```

(`:empty` ignores Angular's comment anchors, which the old `.footer:empty` already relied on.)

- [ ] **Step 4: Migrate the two consumers**

Add-feed footer becomes:

```html
    <a footerStart class="browse" routerLink="/discover" (click)="ref.close()">
      {{ 'discover.browseCatalog' | transloco }}
    </a>
    <a footerStart class="browse" routerLink="/settings/feed-directory" (click)="ref.close()">
      {{ 'settings.feedDirectory.browse' | transloco }}
    </a>
```

Delete the `.browse-links` and `.browse-links ~ app-button` rules from `add-feed-dialog.component.scss` (keep `.browse`).

Edit-subscription: replace `footer` with `footerStart` on the Unsubscribe `app-button`, and delete the `.unsubscribe` rule and its `class="unsubscribe"` attribute if nothing else uses it (`grep -n unsubscribe` the component's files and specs first).

Update `docs/design-language.md`. In the overlay-panel "Content slots" paragraph, after "`[footer]` lands in the footer row", add: "; `[footerStart]` lands at the row's start, stacked in a column (secondary links, a destructive action), while `[footer]` buttons stay at the end". Change "The subheader and the footer hide themselves when empty" to cover both footer areas.

- [ ] **Step 5: Run the specs**

Run: `docker compose exec -T frontend npm test -- overlay-panel add-feed-dialog edit-subscription-dialog`
Expected: PASS. Fix any spec that selected `.browse-links` or `.unsubscribe` by switching it to the attribute it now uses (`.footer-start a`, `[data-test=edit-feed-unsubscribe]`).

- [ ] **Step 6: Gate and commit**

Run: `cd frontend && npm run check`

```bash
git add frontend/src/app/shared/overlay-panel frontend/src/app/reader/feeds/add-feed frontend/src/app/reader/feeds/manage docs/design-language.md
git commit -m "refactor(#2182): the overlay panel's footer has a start area, so dialogs drop their own footer layout"
```

The controller live-checks both dialogs at desktop and 375px after the task (the implementer has no login).

---

### Task 4: One translated problem lookup with convention keys

**Files:**
- Create: `frontend/src/app/core/i18n/translation-of.ts`, `translation-of.spec.ts`
- Modify: `frontend/src/app/core/problem.ts`, `problem.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.ts` (drop `FAILURE_TEXT_KEYS`, `PROBLEM_TEXT_KEYS`, `NO_PREVIEW_TEXT_KEYS`), `.spec.ts`
- Modify: `frontend/src/app/reader/article/composer/composer-dialog.component.ts` (lines ~141 and ~186)

**Interfaces:**
- Produces: `translationOf(i18n: TranslocoService, key: string): string | null`, which gives the active language's text for `key`, or null when the dictionary has no such key.
- Produces: `problemText(problem: Problem, i18n: TranslocoService): string`, which gives the text under `problem.<type>`, else `problemMessage(problem)`.
- Key conventions: `problem.<type>`; `dialog.addFeed.failure.<reason>` with `dialog.addFeed.failGeneric` as the fallback; `dialog.addFeed.noPreview.<reason>` with `dialog.addFeed.noPreviewGeneric` as the fallback.

Transloco 8.4 stores each language flattened (`setTranslation` runs `flatten`), so `getTranslation(lang)` is keyed by the dotted path. A presence check avoids the dev-mode missing-key warning that a translate-and-compare would log.

- [ ] **Step 1: Write the failing specs**

`frontend/src/app/core/i18n/translation-of.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { translationOf } from './translation-of';

describe('translationOf', () => {
  let i18n: TranslocoService;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    i18n = TestBed.inject(TranslocoService);
  });

  it('gives the active language text for a key the dictionary has', () => {
    expect(translationOf(i18n, 'dialog.cancel')).toBe('Cancel');
    i18n.setActiveLang('de');
    expect(translationOf(i18n, 'dialog.cancel')).toBe('Abbrechen');
  });

  it('gives null for a key the dictionary lacks', () => {
    expect(translationOf(i18n, 'problem.no_such_type')).toBeNull();
  });
});
```

Check the real `dialog.cancel` values in `en.json` and `de.json` and use them verbatim.

In `problem.spec.ts`, add a `describe('problemText')` with the same TestBed setup:

```ts
  it('translates a problem type the dictionary knows', () => {
    const problem = { type: 'not_a_feed', title: 'Not a feed', status: 422, detail: 'raw' };
    expect(problemText(problem, i18n)).toBe('No feed could be read at that address.');
  });

  it('falls back to the problem message for a type it does not know', () => {
    const problem = { type: 'other', title: 'Other', status: 400, detail: 'The detail.' };
    expect(problemText(problem, i18n)).toBe('The detail.');
  });
```

The add-feed dialog's existing specs for `not_a_feed`, `feed_throttled`, the scrape failure reasons and the no-preview reasons stay unchanged. They prove the move kept every text.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec -T frontend npm test -- translation-of problem`
Expected: FAIL. The modules and exports do not exist.

- [ ] **Step 3: Implement the lookup**

`frontend/src/app/core/i18n/translation-of.ts`:

```ts
import { TranslocoService } from '@jsverse/transloco';

/** The active language's text for `key`, or null when the dictionary has none — for keys built from server codes. */
export function translationOf(i18n: TranslocoService, key: string): string | null {
  return key in i18n.getTranslation(i18n.getActiveLang()) ? i18n.translate(key) : null;
}
```

In `core/problem.ts`:

```ts
import { TranslocoService } from '@jsverse/transloco';
import { translationOf } from './i18n/translation-of';

/** The problem in the reader's language when `problem.<type>` is translated, else its most specific message. */
export function problemText(problem: Problem, i18n: TranslocoService): string {
  return translationOf(i18n, `problem.${problem.type}`) ?? problemMessage(problem);
}
```

- [ ] **Step 4: Move the dictionary keys**

In both `en.json` and `de.json`, add a top-level `"problem"` object containing `"not_a_feed"` (the current `dialog.addFeed.notAFeed` text) and `"feed_throttled"` (the current `dialog.addFeed.failThrottled` text). Under `dialog.addFeed`:
- replace `failBlocked`, `failThrottled`, `failUnreachable`, `failNotScrapable` and `failTooLarge` with `"failure": { "blocked", "throttled", "unreachable", "not_scrapable", "too_large" }` carrying the same texts;
- replace `noPreviewHiddenProfile` and `noPreviewConnectBluesky` with `"noPreview": { "hidden_profile", "connect_bluesky_account" }`;
- keep `failGeneric` and `noPreviewGeneric`, and delete `notAFeed`.

Do it with a short `python3 -I` script that loads, edits and dumps each file with `ensure_ascii=False, indent=2`, and keep the key order around the edit. Then `grep -rn "notAFeed\|failBlocked\|failThrottled\|failUnreachable\|failNotScrapable\|failTooLarge\|noPreviewHiddenProfile\|noPreviewConnectBluesky" frontend/src frontend/e2e` must return nothing.

- [ ] **Step 5: Use it**

In `add-feed-dialog.component.ts`, delete the three `…_TEXT_KEYS` maps and write:

```ts
  failureText(reason: ScrapeFailureReason): string {
    return (
      translationOf(this.i18n, `dialog.addFeed.failure.${reason}`) ??
      this.i18n.translate('dialog.addFeed.failGeneric')
    );
  }

  noPreviewText(reason: NoPreviewReason): string {
    return (
      translationOf(this.i18n, `dialog.addFeed.noPreview.${reason}`) ??
      this.i18n.translate('dialog.addFeed.noPreviewGeneric')
    );
  }
```

`showError` becomes `this.error.set(problemText(parseProblem(error), this.i18n));`. Keep the `failureText` docblock's point about unknown future reasons, shortened to one line. Update the imports (`problemText`, `translationOf`; drop `problemMessage` if unused).

In `composer-dialog.component.ts`, replace both `problemMessage(parseProblem(error))` calls with `problemText(parseProblem(error), this.i18n)`. If the component does not already inject `TranslocoService`, add `private readonly i18n = inject(TranslocoService);`, and make sure its spec's TestBed provides Transloco (`provideTranslocoTesting()`).

- [ ] **Step 6: Run the specs and the gate**

Run: `docker compose exec -T frontend npm test -- translation-of problem add-feed-dialog composer-dialog i18n-dictionaries`
Expected: PASS.
Run: `cd frontend && npm run check`

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/core frontend/public/i18n frontend/src/app/reader/feeds/add-feed frontend/src/app/reader/article/composer
git commit -m "refactor(#2182): problems and reason codes translate through convention keys, looked up in core"
```

---

### Task 5: The add-feed dialog trusts the candidate and keeps one busy flag

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`FeedCandidate`)
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.ts`, `.html`, `.spec.ts`
- Modify: `frontend/src/app/reader/feeds/manage/edit-subscription-dialog.component.ts` (`toggle()`, R5)
- Modify any other spec or e2e fixture that builds a `FeedCandidate` (find them with `grep -rln "format: '" frontend/src frontend/e2e | xargs grep -l "candidates"`)

**Interfaces:**
- Consumes: Task 2's `label` and optional `storedFormat` on each candidate.
- Consumes: `toggled<T>(current: ReadonlySet<T>, value: T): ReadonlySet<T>` from `frontend/src/app/core/toggled-set.ts`.

- [ ] **Step 1: Write the failing specs**

The spec already covers labels (`.badge.format` is `Scraped`, `Mixcloud`, `WordPress`), formats and titles on preview and subscribe, and both busy guards ("sends no second discover request while a subscribe is in flight" / "…while one is in flight"). Those tests stay. Their fixtures change so that the server, not the dialog, supplies the facts:

- Every candidate fixture in `add-feed-dialog.component.spec.ts` gains `label`, and the verbatim ones gain `storedFormat`, exactly as Task 2's backend sends them:
  `{ url: 'https://f/rss', title: 'RSS', format: 'rss', label: 'RSS', preview: RSS_PREVIEW }`,
  `{ url: 'https://page.example/', title: 'Page', format: 'scraped', label: 'Scraped', storedFormat: 'scraped' }`,
  `{ url: 'https://api.mixcloud.com/johndigweed/cloudcasts/', title: 'John Digweed | Mixcloud', format: 'mixcloud-api', label: 'Mixcloud', storedFormat: 'mixcloud-api' }`,
  `{ url: 'https://wp.example/wp-json/wp/v2/posts', title: 'WP', format: 'wp-json', label: 'WordPress', storedFormat: 'wp-json' }`. The Bluesky fixtures get `label: 'Bluesky'`.
- R3: in "labels a scraped candidate and subscribes/previews with the scraped format" the subscribe body becomes `{ url: 'https://page.example/', format: 'scraped', title: 'Page' }`. In "subscribes the picked card and closes with the created subscription", add before the flush:

```ts
    const subscribeRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subscribeRequest.request.body).toEqual({ url: 'https://f/rss', title: 'RSS' });
    subscribeRequest.flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
```

(it replaces the existing `ctrl.expectOne(...).flush(...)` chain; `ReaderApi.subscribe()` omits an empty `tagIds` and an absent `format`).

Then add two new tests:

```ts
  it('shows the label the server sent rather than one of its own', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://f/rss' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [
        { url: 'https://f/rss', title: 'RSS', format: 'rss', label: 'Really Simple', preview: RSS_PREVIEW },
      ],
    });
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.querySelector('.badge.format')?.textContent?.trim()).toBe('Really Simple');
  });

  it('disables every card Subscribe while one subscribe runs', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://f/rss' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/feeds/discover').flush({
      candidates: [
        { url: 'https://f/rss', title: 'RSS', format: 'rss', label: 'RSS', preview: RSS_PREVIEW },
        { url: 'https://f/atom', title: 'Atom', format: 'atom', label: 'Atom' },
      ],
    });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    subscribeButton(fixture).click();
    fixture.detectChanges();
    const buttons = [...element.querySelectorAll<HTMLButtonElement>('.card .subscribe')];
    expect(buttons.map((button) => button.disabled)).toEqual([true, true]);
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ subscription: { id: 1 } }, { status: 201, statusText: 'Created' });
  });
```

The second test pins today's behaviour across the flag merge; no lazy preview request goes out for the atom card, because only the first card auto-expands. The footer Preview button is hidden while cards show, so the merge's only new effect is the guard in `submit()`, which the two existing "sends no second discover request…" tests already cover.

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec -T frontend npm test -- add-feed-dialog`
Expected: FAIL. "shows the label the server sent" fails because the dialog still renders `formatLabel('rss')` = `RSS`, and the R3 body assertions fail because the dialog drops the title for `rss` and `scraped`.

- [ ] **Step 3: Implement**

`models.ts` `FeedCandidate` gains:

```ts
  /** How the dialog names the candidate's format ("RSS", "WordPress"). */
  label: string;
  /** The `format` subscribe and preview must send; absent when the server works it out again. */
  storedFormat?: string;
```

In `add-feed-dialog.component.ts`:
- delete `VERBATIM_FORMATS`, `FORMAT_LABELS`, `formatLabel()` and `storedFormat()`;
- replace `loading` and `subscribing` with `readonly busy = signal<'discovering' | 'subscribing' | null>(null);`;
- `readonly checked = signal<ReadonlySet<number>>(new Set());` and `toggleTag(id: number): void { this.checked.update((set) => toggled(set, id)); }`;
- `submit()`: `if (this.form.invalid || this.busy()) return;`
- `pick()`:

```ts
  pick(candidate: FeedCandidate): void {
    this.busy.set('subscribing');
    this.error.set(null);
    this.api
      .subscribe({
        url: candidate.url,
        format: candidate.storedFormat,
        tagIds: [...this.checked()],
        title: candidate.title ?? undefined,
      })
      .subscribe({
        next: (response) => this.ref.close(response.subscription),
        error: (error: HttpErrorResponse) => {
          this.busy.set(null);
          this.showError(error);
        },
      });
  }
```

- `discover()`: `this.busy.set('discovering')` at the start, and `this.busy.set(null)` in both `next` and `error` where `loading.set(false)` was;
- `ensurePreview()`: `this.api.previewFeed(candidate.url, candidate.storedFormat)`.

In `add-feed-dialog.component.html`: `@if (busy() === 'discovering')` for the searching line; `{{ c.label }}` in the format badge; `[disabled]="busy() !== null"` on each card's Subscribe and on the footer Preview button.

In `edit-subscription-dialog.component.ts`: `checked` becomes `signal<ReadonlySet<number>>(…)`, and `toggle(id)` becomes `this.checked.update((set) => toggled(set, id));`. Pass the set to the tag picker unchanged; its input is already `ReadonlySet<number>`.

Run `grep -rn "subscribing()\|loading()\|formatLabel\|VERBATIM_FORMATS" frontend/src/app/reader/feeds/add-feed`. It must return nothing.

- [ ] **Step 4: Run the specs and the gate**

Run: `docker compose exec -T frontend npm test -- add-feed-dialog edit-subscription-dialog`
Expected: PASS.
Run: `cd frontend && npm run check`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader frontend/e2e
git commit -m "refactor(#2182): the add-feed dialog uses the candidate's label and stored format, one busy flag and the shared set toggle"
```

---

### After the tasks (controller)

- Full gates: `cd backend && composer test:parallel` and `docker compose exec php composer test:parallel` (both legs, in parallel), `composer check`, `composer md`, `COMPOSER_PROCESS_TIMEOUT=0 composer infection:diff`, then `cd frontend && npm run check`, `npm run e2e` (feed-directory, add-feed-mobile and reader-smoke specs) and `cd backend && composer e2e`.
- Live check on :4200. Add-feed from the directory: the footer links sit at the start and Cancel/Preview stay on one row, at desktop and 375px. The edit-feed dialog: Unsubscribe sits at the start. A direct feed shows the label "RSS"; a page offering WordPress shows "WordPress" and subscribes. Cancel each time, so nothing stays subscribed.
- Then resume the #2182 ledger: delete the SDD workspace, push, and open the PR.
