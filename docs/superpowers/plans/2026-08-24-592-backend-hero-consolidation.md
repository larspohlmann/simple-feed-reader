# Backend Hero Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move every feed-hero duplicate-image decision to the backend, so the
rule has exactly one implementation and `frontend/src/app/reader/feed-hero-image.ts`
can be deleted.

**Architecture:** A `HeroImage` value object carries a picture and its declared
dimensions. `HeroImageSelector` (the renamed `LeadImageSelector`) holds the one
lead/repeat rule and takes a `HeroImage` in and out. A new `ReaderHeroResolver`
applies that rule three times — extraction image against extracted body, feed
image against extracted body, feed image against feed body — and returns a
`ReaderHeroes` pair. `GET /api/entries/{id}/reader` emits `readerHero` and
`originalHero` on both its branches, so the client picks one by mode with no
request and no rule of its own.

**Tech Stack:** PHP 8.4 / Symfony 7.4, PHPUnit, `\Dom\HTMLDocument`; Angular 20
with signals, Jest.

**Spec:** [docs/superpowers/specs/2026-08-24-592-backend-hero-consolidation-design.md](../specs/2026-08-24-592-backend-hero-consolidation-design.md)

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- Clean Code is mandatory (`CLAUDE.md`): `final readonly` with constructor
  promotion, guard clauses over nesting, names that reveal intent, comments that
  explain *why*.
- Every `src` file touched must be PHPMD-clean before commit, not merely free of
  new findings.
- PHPStan level max over `src` **and** `tests`. No new baseline entries. No
  `@phpstan-ignore` without a comment saying why.
- Commit messages use `type(#592): summary`. The issue number is the scope.
- Frontend: standalone components and signals, Prettier at 100 columns, no hex
  colours or raw `px` outside `src/app/theme/`, component styles in a sibling
  `.scss`.
- The payload stays JSON in / `application/problem+json` out, with no
  browser-only input. A native Swift client must need no hero logic.
- No schema change and no migration in this branch.
- Run backend tests with `php bin/phpunit` from `backend/`.
- Run frontend checks with `npm run check` from `frontend/`.

---

### Task 1: The `HeroImage` value object and the renamed selector

Introduce the typed hero and move the rule onto it. `ArticleExtractor` adapts at
its single call site so the tree stays green and behaviour stays identical; Task 3
removes that adapter.

**Files:**
- Create: `backend/src/Service/Reader/HeroImage.php`
- Rename: `backend/src/Service/Reader/LeadImageSelector.php` → `HeroImageSelector.php`
- Modify: `backend/src/Service/Reader/ArticleExtractor.php:33,72`
- Rename: `backend/tests/Service/Reader/LeadImageSelectorTest.php` → `HeroImageSelectorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `App\Service\Reader\HeroImage` — `final readonly`, `__construct(public string $url, public ?int $width = null, public ?int $height = null)`.
  - `App\Service\Reader\HeroImageSelector::select(?HeroImage $candidate, string $bodyHtml): ?HeroImage` — returns the same instance when the candidate survives, `null` otherwise.

- [ ] **Step 1: Create the value object**

Create `backend/src/Service/Reader/HeroImage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * A picture offered to lead an article, with the dimensions its source declared.
 *
 * Null dimensions mean unknown, not square: the client then reserves no space
 * for the image. A feed usually declares them for its own picture; readability
 * reports none for an og:image it finds on the page.
 */
final readonly class HeroImage
{
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
```

- [ ] **Step 2: Rename the selector and its test, keeping git history**

```bash
cd backend
git mv src/Service/Reader/LeadImageSelector.php src/Service/Reader/HeroImageSelector.php
git mv tests/Service/Reader/LeadImageSelectorTest.php tests/Service/Reader/HeroImageSelectorTest.php
```

- [ ] **Step 3: Rewrite the test to the new signature**

In `backend/tests/Service/Reader/HeroImageSelectorTest.php`, change the class
name, the import and the property, and add a string adapter. Replace the head of
the file (everything down to and including `setUp()`) with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\HeroImage;
use App\Service\Reader\HeroImageSelector;
use PHPUnit\Framework\TestCase;

final class HeroImageSelectorTest extends TestCase
{
    private HeroImageSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new HeroImageSelector();
    }

    /**
     * The rule is about URLs, so the cases below state URLs. Dimensions ride
     * along untouched and have their own case at the end of this class.
     */
    private function selectUrl(?string $candidateUrl, string $bodyHtml): ?string
    {
        $candidate = $candidateUrl === null ? null : new HeroImage($candidateUrl);

        return $this->selector->select($candidate, $bodyHtml)?->url;
    }
```

Then point all 22 existing cases at the adapter:

```bash
cd backend
sed -i '' 's/\$this->selector->select(/$this->selectUrl(/g' tests/Service/Reader/HeroImageSelectorTest.php
```

Re-open the file and confirm the `sed` did **not** touch the `selectUrl()` helper
body itself. It must still read `$this->selector->select($candidate, $bodyHtml)?->url;`.
If `sed` rewrote it, restore that one line by hand.

- [ ] **Step 4: Add the two new cases**

Append these before the closing brace of `HeroImageSelectorTest`:

```php
    public function testIsNotFooledByAnElementWhoseNameMerelyStartsWithImg(): void
    {
        // Ported from the deleted frontend spec (#592): the rule matches the
        // element name `img` exactly, so an `<imgur-embed>` is neither a leading
        // image nor a repeat of the hero.
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>see the <imgur-embed></imgur-embed></p>'));
    }

    public function testKeepsTheDeclaredDimensionsOfAnAcceptedHero(): void
    {
        // The dimensions are the client's aspect-ratio reservation, so the
        // selector must hand back the candidate itself, not a rebuilt copy.
        $hero = new HeroImage('https://cdn.test/hero.jpg', 800, 450);

        $selected = $this->selector->select($hero, '<p>Just words.</p>');

        self::assertSame($hero, $selected);
        self::assertSame(800, $selected?->width);
        self::assertSame(450, $selected?->height);
    }
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/HeroImageSelectorTest.php`
Expected: FAIL — `Class "App\Service\Reader\HeroImageSelector" not found`, or a
type error on `select()`.

- [ ] **Step 6: Change the selector**

In `backend/src/Service/Reader/HeroImageSelector.php` rename the class and change
only `select()`. Leave `parseBody()`, `bodyLeadsWithImage()`, `bodyRepeatsImage()`,
`bodyImageUrls()`, `nodesInRenderOrder()`, `isVisibleText()` and `imageIdentity()`
exactly as they are.

```php
final class HeroImageSelector
{
    /** Standard layout whitespace, excluding U+00A0 which is visible text. */
    private const string LAYOUT_WHITESPACE = " \t\n\r\f\v\0";

    public function select(?HeroImage $candidate, string $bodyHtml): ?HeroImage
    {
        if ($candidate === null || preg_match('#^https?://#i', $candidate->url) !== 1) {
            return null;
        }

        $body = $this->parseBody($bodyHtml);
        if ($body === null) {
            return $candidate;
        }
        if ($this->bodyLeadsWithImage($body) || $this->bodyRepeatsImage($body, $candidate->url)) {
            return null;
        }

        return $candidate;
    }
```

Also update the class docblock. Replace its first sentence, "Decides whether an
article's og:image should lead the reader as a hero.", with:

```
 * Decides whether a candidate picture may lead an article as its hero.
 *
 * This is the only implementation of the rule (#592). It is applied to more than
 * one (candidate, body) pair — see ReaderHeroResolver — so it knows nothing
 * about where either side came from.
```

- [ ] **Step 7: Adapt the one call site so the tree stays green**

In `backend/src/Service/Reader/ArticleExtractor.php`, rename the promoted
property at line 33 from `$leadImageSelector` to `$heroImageSelector`, change its
type to `HeroImageSelector`, and wrap the call at line 72:

```php
            image: $this->heroImageSelector->select(
                $article->image === null ? null : new HeroImage($article->image),
                $clean,
            )?->url,
```

Task 3 deletes this adapter. Add no comment about it; it is gone within the
branch.

- [ ] **Step 8: Run the reader suite to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/`
Expected: PASS, all cases.

- [ ] **Step 9: Prove nothing else referenced the old name**

Run: `cd backend && grep -rn "LeadImageSelector" src tests config`
Expected: no output.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Reader/HeroImage.php backend/src/Service/Reader/HeroImageSelector.php backend/src/Service/Reader/ArticleExtractor.php backend/tests/Service/Reader/HeroImageSelectorTest.php
git commit -m "refactor(#592): give the hero rule a typed candidate and a name of its own"
```

---

### Task 2: The resolver

The whole hero policy in one class, not yet wired to anything.

**Files:**
- Create: `backend/src/Service/Reader/ReaderHeroes.php`
- Create: `backend/src/Service/Reader/ReaderHeroResolver.php`
- Test: `backend/tests/Service/Reader/ReaderHeroResolverTest.php`

**Interfaces:**
- Consumes: `HeroImage`, `HeroImageSelector::select()` from Task 1.
- Produces:
  - `App\Service\Reader\ReaderHeroes` — `final readonly`, `__construct(public ?HeroImage $readerHero, public ?HeroImage $originalHero)`.
  - `App\Service\Reader\ReaderHeroResolver::__construct(private HeroImageSelector $selector)` and `resolve(Entry $entry, ExtractionResult $result): ReaderHeroes`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Reader/ReaderHeroResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\HeroImageSelector;
use App\Service\Reader\ReaderHeroResolver;
use PHPUnit\Framework\TestCase;

final class ReaderHeroResolverTest extends TestCase
{
    private ReaderHeroResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ReaderHeroResolver(new HeroImageSelector());
    }

    private function entry(?string $contentHtml, ?string $summary = null): Entry
    {
        $entry = new Entry(
            new Feed('https://site.test/feed.xml'),
            'guid-1',
            'https://site.test/post',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setContentHtml($contentHtml);
        $entry->setSummary($summary);

        return $entry;
    }

    private function extracted(string $contentHtml, ?string $imageCandidate): ExtractionResult
    {
        return ExtractionResult::ok(
            url: 'https://site.test/post',
            title: 'Post',
            byline: null,
            siteName: null,
            contentHtml: $contentHtml,
            excerpt: null,
            image: $imageCandidate,
        );
    }

    public function testTheExtractionImageWinsTheReaderHero(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted('<p>Extracted body.</p>', 'https://cdn.test/og.jpg'),
        );

        self::assertSame('https://cdn.test/og.jpg', $heroes->readerHero?->url);
        // readability reports no dimensions for an og:image.
        self::assertNull($heroes->readerHero?->width);
    }

    public function testTheFeedImageBacksTheReaderHeroWhenTheExtractionHasNone(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted body.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
        self::assertSame(800, $heroes->readerHero?->width);
        self::assertSame(450, $heroes->readerHero?->height);
    }

    public function testTheFeedImageBacksTheReaderHeroWhenTheExtractionImageIsSuppressed(): void
    {
        // The og:image repeats a picture the extracted body already shows, so it
        // is suppressed; the feed's own, different picture may still lead.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);
        $extractedBody = '<p>Intro.</p><img src="https://cdn.test/og.jpg" alt="">';

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted($extractedBody, 'https://cdn.test/og.jpg'),
        );

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
    }

    public function testTheReaderHeroIsJudgedAgainstTheExtractedBodyNotTheFeedBody(): void
    {
        // The feed body repeats the feed picture and the extracted body does not.
        // Only the original hero may be suppressed.
        $entry = $this->entry('<p>Intro.</p><img src="https://cdn.test/feed.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted body.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
        self::assertNull($heroes->originalHero);
    }

    public function testTheOriginalHeroIsSuppressedWhenTheFeedBodyLeadsWithAnImage(): void
    {
        $entry = $this->entry('<figure><img src="https://cdn.test/other.jpg" alt=""></figure><p>x</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->originalHero);
    }

    public function testTheOriginalHeroFallsBackToTheSummaryWhenThereIsNoContentHtml(): void
    {
        // Many feeds populate only one of contentHtml and summary; the rule must
        // judge the body the client will actually render.
        $entry = $this->entry(null, '<p>a</p><img src="https://cdn.test/feed.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->originalHero);
    }

    public function testTheOriginalHeroLeadsAnEntryWithNoBodyAtAll(): void
    {
        $entry = $this->entry(null);
        $entry->setImage('https://cdn.test/feed.jpg', null, null);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->originalHero?->url);
        // Unknown dimensions pass through rather than being guessed.
        self::assertNull($heroes->originalHero?->width);
        self::assertNull($heroes->originalHero?->height);
    }

    public function testAFailedExtractionStillResolvesTheOriginalHero(): void
    {
        // The client forces the original view on a failed extraction, where the
        // feed's picture is the only one there is.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, ExtractionResult::failed('https://site.test/post', 'fetch'));

        self::assertNull($heroes->readerHero);
        self::assertSame('https://cdn.test/feed.jpg', $heroes->originalHero?->url);
    }

    public function testAnEntryWithoutAPictureResolvesNoHeroes(): void
    {
        $heroes = $this->resolver->resolve($this->entry('<p>Feed body.</p>'), $this->extracted('<p>x</p>', null));

        self::assertNull($heroes->readerHero);
        self::assertNull($heroes->originalHero);
    }

    public function testANonHttpFeedPictureIsRejected(): void
    {
        // The persisted URL is https server-side, but the guard is the boundary,
        // not the expectation — a javascript: URL must never reach an <img src>.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('javascript:alert(1)', null, null);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>x</p>', null));

        self::assertNull($heroes->readerHero);
        self::assertNull($heroes->originalHero);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/ReaderHeroResolverTest.php`
Expected: FAIL — `Class "App\Service\Reader\ReaderHeroResolver" not found`.

The test builds the result with the field's **current** name, `image:`. Task 3
renames that field and updates this test with it. Do not rename it here: the
extractor still decides the hero at this point, so the value is not yet a
candidate and the name would be wrong.

- [ ] **Step 3: Create the result pair**

Create `backend/src/Service/Reader/ReaderHeroes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The two pictures a reader response offers: one for each body the client can
 * put on screen. Serving both lets the Reader/Original toggle switch without a
 * request, and keeps the duplicate rule off every client (#592).
 */
final readonly class ReaderHeroes
{
    public function __construct(
        public ?HeroImage $readerHero,
        public ?HeroImage $originalHero,
    ) {
    }
}
```

- [ ] **Step 4: Create the resolver**

Create `backend/src/Service/Reader/ReaderHeroResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;

/**
 * Picks the hero for each body the reader can show.
 *
 * The reader view renders the extracted article; the original view renders the
 * feed's own body. Each has its own leading picture, and the duplicate rule has
 * to be judged against the body that will actually be on screen. Resolving both
 * here is what lets the rule live in exactly one place (#592): the client picks
 * a field, it does not decide anything.
 */
final readonly class ReaderHeroResolver
{
    public function __construct(private HeroImageSelector $selector)
    {
    }

    public function resolve(Entry $entry, ExtractionResult $result): ReaderHeroes
    {
        $feedPicture = $this->feedPicture($entry);

        return new ReaderHeroes(
            readerHero: $this->readerHero($result, $feedPicture),
            originalHero: $this->selector->select($feedPicture, $this->feedBody($entry)),
        );
    }

    /**
     * The extraction's own picture leads when it survives the rule. When it does
     * not — the page offered none, or the extracted body already shows it — the
     * feed's picture is offered against that same body rather than leaving the
     * article imageless.
     */
    private function readerHero(ExtractionResult $result, ?HeroImage $feedPicture): ?HeroImage
    {
        if (!$result->ok) {
            return null;
        }

        $extractedBody = (string) $result->contentHtml;

        return $this->selector->select($this->extractedPicture($result), $extractedBody)
            ?? $this->selector->select($feedPicture, $extractedBody);
    }

    /** Readability reports no dimensions for the og:image it finds. */
    private function extractedPicture(ExtractionResult $result): ?HeroImage
    {
        return $result->image === null ? null : new HeroImage($result->image);
    }

    private function feedPicture(Entry $entry): ?HeroImage
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new HeroImage($url, $entry->getImageWidth(), $entry->getImageHeight());
    }

    /**
     * The body the original view renders. Many feeds populate only one of
     * contentHtml and summary, so the client falls through both; the rule has to
     * judge the same string.
     */
    private function feedBody(Entry $entry): string
    {
        return $entry->getContentHtml() ?? $entry->getSummary() ?? '';
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Reader/ReaderHeroResolverTest.php`
Expected: PASS, 10 cases.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/ReaderHeroes.php backend/src/Service/Reader/ReaderHeroResolver.php backend/tests/Service/Reader/ReaderHeroResolverTest.php
git commit -m "feat(#592): resolve one hero per reader body in a single service"
```

---

### Task 3: Wire the resolver into the reader payload

The extractor stops deciding, the endpoint starts serving both heroes.

**Files:**
- Modify: `backend/src/Service/Reader/ExtractionResult.php`
- Modify: `backend/src/Service/Reader/ArticleExtractor.php`
- Modify: `backend/src/Http/ReaderJson.php`
- Modify: `backend/src/Controller/Api/EntryController.php`
- Test: `backend/tests/Service/Reader/ArticleExtractorTest.php`
- Test: `backend/tests/Controller/Api/EntryReaderControllerTest.php`

**Interfaces:**
- Consumes: `ReaderHeroResolver::resolve()` and `ReaderHeroes` from Task 2.
- Produces: the payload the frontend reads in Task 4 —
  `{status:'ok', url, title, byline, siteName, contentHtml, excerpt, readerHero, originalHero, extractedAt}`
  and `{status:'failed', url, reason, readerHero: null, originalHero}`, where a
  hero is `{url: string, width: int|null, height: int|null}` or `null`.

- [ ] **Step 1: Rename the field on the extraction result**

The extractor is about to stop deciding, so the field stops being a decision.
Rename it in `ExtractionResult` and at every reader:

| File | What |
|---|---|
| `backend/src/Service/Reader/ExtractionResult.php` | the promoted property and the `ok()` parameter |
| `backend/src/Service/Reader/ArticleExtractor.php` | the `image:` named argument (Step 2 rewrites this line anyway) |
| `backend/src/Service/Reader/ReaderHeroResolver.php` | `$result->image` in `extractedPicture()` |
| `backend/src/Http/ReaderJson.php` | `$r->image` (Step 7 rewrites this file anyway) |
| `backend/tests/Service/Reader/ReaderHeroResolverTest.php` | the `image:` named argument in `extracted()` |
| `backend/tests/Service/Reader/ArticleExtractorTest.php` | `$result->image` at lines 84, 115, 131 |
| `backend/tests/Controller/Api/EntryReaderControllerTest.php` | the `image:` named argument |

In `backend/src/Service/Reader/ExtractionResult.php`, replace the property's
comment as well:

```php
        // The page's own picture — readability finds the og:image even when it
        // sits outside the extracted content. An undecided candidate: whether it
        // may lead the article is ReaderHeroResolver's call, not the extractor's.
        public ?string $imageCandidate,
```

- [ ] **Step 2: Take the selector out of the extractor**

In `backend/src/Service/Reader/ArticleExtractor.php`:

- Remove the `HeroImageSelector $heroImageSelector` constructor parameter.
  `HeroImage` and `HeroImageSelector` share this file's namespace, so there is no
  import to remove.
- Replace the wrapped call with a plain pass-through:

```php
            imageCandidate: $article->image,
```

- Replace the last paragraph of the class docblock, "Never throws for an ordinary
  failure — …", by keeping it and adding after it:

```
 * The lead picture is carried through undecided: whether it may lead the
 * article depends on the body the client shows, which is ReaderHeroResolver's
 * concern (#592).
```

- [ ] **Step 3: Update the extractor's tests to the new field**

In `backend/tests/Service/Reader/ArticleExtractorTest.php`:

- `$result->image` becomes `$result->imageCandidate` at lines 84, 115 and 131.
- Wherever the fixture wires the extractor, drop the `LeadImageSelector`/
  `HeroImageSelector` constructor argument (lines around 58 and 165).
- Rename `testEmitsLeadImageWhenTheBodyShowsADifferentPicture` to
  `testCarriesTheOgImageThroughAsACandidate` and change its comment: the #505
  suppression assertion now lives in `ReaderHeroResolverTest`; this case only
  proves the candidate survives extraction.

- [ ] **Step 4: Run the extractor tests**

Run: `cd backend && php bin/phpunit tests/Service/Reader/`
Expected: PASS.

- [ ] **Step 5: Write the failing payload test**

In `backend/tests/Controller/Api/EntryReaderControllerTest.php`:

Give `seedEntry()` a feed body and a feed picture, so both heroes have something
to resolve. Insert before `$em->persist($entry);`:

```php
        $entry->setContentHtml('<p>The feed body.</p>');
        $entry->setImage('https://example.com/feed.jpg', 800, 450);
```

In `testOwnedEntryOkReturnsExtractedArticle`, change the fake's named argument
`image:` to `imageCandidate:`, then replace the `leadImage` assertion with:

```php
        self::assertSame(
            ['url' => 'https://example.com/lead.jpg', 'width' => null, 'height' => null],
            $body['readerHero'],
        );
        // The feed body carries no picture of its own, so the feed's own image
        // leads the original view.
        self::assertSame(
            ['url' => 'https://example.com/feed.jpg', 'width' => 800, 'height' => 450],
            $body['originalHero'],
        );
        self::assertArrayNotHasKey('leadImage', $body);
```

In `testOwnedEntryFetchFailureReturnsFailedStatus`, add after the existing
assertions:

```php
        // A failed extraction is exactly when the feed's own picture is the only
        // one there is, so the original hero must still be resolved.
        self::assertNull($body['readerHero']);
        self::assertSame(
            ['url' => 'https://example.com/feed.jpg', 'width' => 800, 'height' => 450],
            $body['originalHero'],
        );
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryReaderControllerTest.php`
Expected: FAIL — `Undefined array key "readerHero"`.

- [ ] **Step 7: Emit the heroes**

Replace `backend/src/Http/ReaderJson.php` in full:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\ExtractionResult;
use App\Service\Reader\HeroImage;
use App\Service\Reader\ReaderHeroes;

final class ReaderJson
{
    /**
     * Both heroes ride on both branches. A failed extraction has no extracted
     * body to lead, so its reader hero is always null — but the field is there,
     * so any client reads the same two fields whatever the status (#592).
     *
     * @return array{status: 'ok', url: string, title: string, byline: string|null,
     *   siteName: string|null, contentHtml: string, excerpt: string|null,
     *   readerHero: array{url: string, width: int|null, height: int|null}|null,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null,
     *   extractedAt: string}
     *  |array{status: 'failed', url: string|null, reason: string,
     *   readerHero: null,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null}
     */
    public static function one(ExtractionResult $r, ReaderHeroes $heroes, \DateTimeImmutable $now): array
    {
        if (!$r->ok) {
            return [
                'status' => 'failed',
                'url' => $r->url,
                'reason' => (string) $r->reason,
                'readerHero' => null,
                'originalHero' => self::hero($heroes->originalHero),
            ];
        }

        return [
            'status' => 'ok',
            'url' => (string) $r->url,
            'title' => (string) $r->title,
            'byline' => $r->byline,
            'siteName' => $r->siteName,
            'contentHtml' => (string) $r->contentHtml,
            'excerpt' => $r->excerpt,
            'readerHero' => self::hero($heroes->readerHero),
            'originalHero' => self::hero($heroes->originalHero),
            'extractedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{url: string, width: int|null, height: int|null}|null */
    private static function hero(?HeroImage $hero): ?array
    {
        return $hero === null
            ? null
            : ['url' => $hero->url, 'width' => $hero->width, 'height' => $hero->height];
    }
}
```

- [ ] **Step 8: Wire the controller**

In `backend/src/Controller/Api/EntryController.php`:

- Add `use App\Service\Reader\ReaderHeroResolver;` to the imports.
- Add `private ReaderHeroResolver $readerHeroes,` to the constructor, after
  `private ArticleExtractorInterface $extractor,`.
- Change the last statement of `reader()`:

```php
        $heroes = $this->readerHeroes->resolve($entry, $result);

        return new JsonResponse(ReaderJson::one($result, $heroes, $this->clock->now()));
```

The action still only reads the request, delegates and returns, so
`ThinControllerRule` is satisfied. No private helper is added.

- [ ] **Step 9: Run the payload test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryReaderControllerTest.php`
Expected: PASS.

- [ ] **Step 10: Run the whole backend suite**

Run: `cd backend && php bin/phpunit`
Expected: PASS. If `FakeArticleExtractor` or another test builds an
`ExtractionResult` with `image:`, fix that named argument to `imageCandidate:`.

- [ ] **Step 11: Prove the old field is gone from the backend**

```bash
cd backend
grep -rn "leadImage" src tests
grep -rn "image" src/Service/Reader/ExtractionResult.php
```

Expected: the first grep prints nothing. The second prints only
`imageCandidate` lines — no bare `$image`.

- [ ] **Step 12: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#592): serve a resolved hero per reader body from the API"
```

---

### Task 4: Delete the frontend rule

**Files:**
- Delete: `frontend/src/app/reader/feed-hero-image.ts`
- Delete: `frontend/src/app/reader/feed-hero-image.spec.ts`
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-cache.service.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.html`
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`
- Test: `frontend/src/app/reader/reader-content.service.spec.ts`, `reader-cache.service.spec.ts` (fixtures only)

**Interfaces:**
- Consumes: the payload from Task 3.
- Produces: `HeroImageDto` in `models.ts`; `ReaderViewComponent.hero()` returning
  `HeroImageDto | null`.

- [ ] **Step 1: Write the failing component tests**

In `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`, extend
the `okContent` fixture and add a failure fixture next to it:

```ts
const okContent = (over: Partial<ReaderArticle> = {}): ReaderArticle => ({
  status: 'ok',
  contentHtml: '<p>READER</p>',
  url: '',
  title: '',
  byline: null,
  siteName: null,
  excerpt: null,
  readerHero: null,
  originalHero: null,
  extractedAt: '',
  ...over,
});

const failedContent = (over: Partial<ReaderFailure> = {}): ReaderFailure => ({
  status: 'failed',
  reason: 'fetch',
  url: null,
  readerHero: null,
  originalHero: null,
  ...over,
});
```

Import `ReaderFailure` from `../models`. Then replace every
`{ status: 'failed', reason: 'fetch', url: null }` literal in this file with
`failedContent()`:

```bash
cd frontend
grep -n "status: 'failed'" src/app/reader/reader-view/reader-view.component.spec.ts
```

Replace the existing hero test with these five:

```ts
  const hero = (f: { nativeElement: unknown }) =>
    (f.nativeElement as HTMLElement).querySelector('.lead-image') as HTMLImageElement | null;

  it('renders the reader hero the backend resolved for the extracted body', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({ readerHero: { url: 'https://img.test/hero.jpg', width: 800, height: 450 } }),
      ),
    );

    const img = hero(mount(entry()));

    expect(img).not.toBeNull();
    expect(img!.getAttribute('src')).toBe('https://img.test/hero.jpg');
    expect(img!.getAttribute('width')).toBe('800');
    expect(img!.getAttribute('height')).toBe('450');
  });

  it('swaps to the original hero on toggle without asking the server again', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({
          readerHero: { url: 'https://img.test/reader.jpg', width: null, height: null },
          originalHero: { url: 'https://img.test/feed.jpg', width: 800, height: 450 },
        }),
      ),
    );
    const f = mount(entry());
    expect(hero(f)!.getAttribute('src')).toBe('https://img.test/reader.jpg');

    TestBed.inject(ReaderModeService).toggle();
    f.detectChanges();

    expect(hero(f)!.getAttribute('src')).toBe('https://img.test/feed.jpg');
    expect(loadMock).toHaveBeenCalledTimes(1);
  });

  it('renders the original hero when extraction failed', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        failedContent({
          originalHero: { url: 'https://img.test/feed.jpg', width: 800, height: 450 },
        }),
      ),
    );

    expect(hero(mount(entry()))!.getAttribute('src')).toBe('https://img.test/feed.jpg');
  });

  it('hides a hero whose image fails to load', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({ readerHero: { url: 'https://img.test/gone.jpg', width: null, height: null } }),
      ),
    );
    const f = mount(entry());

    hero(f)!.dispatchEvent(new Event('error'));
    f.detectChanges();

    expect(hero(f)).toBeNull();
  });

  it('renders no hero when the backend resolved none', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));

    expect(hero(mount(entry()))).toBeNull();
  });
```

Import `ReaderModeService` from `../reader-mode.service`.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd frontend && ./node_modules/.bin/jest src/app/reader/reader-view`
Expected: FAIL — TypeScript rejects `readerHero` on `ReaderArticle`.

- [ ] **Step 3: Change the models**

In `frontend/src/app/reader/models.ts`, add above `ReaderArticle`:

```ts
/** A picture the backend chose to lead the article, with the dimensions its
 *  source declared. Null width/height mean unknown, so no space is reserved. */
export interface HeroImageDto {
  url: string;
  width: number | null;
  height: number | null;
}
```

In `ReaderArticle`, replace `leadImage: string | null;` and its comment with:

```ts
  /** The picture to lead the reader view; null when the extracted body has its
   *  own leading image, or repeats this one. Resolved server-side (#592). */
  readerHero: HeroImageDto | null;
  /** The picture to lead the original-feed view, resolved against the feed's
   *  own body by the same server-side rule. */
  originalHero: HeroImageDto | null;
```

Add the same two fields to `ReaderFailure`. `readerHero` is always null there:

```ts
export interface ReaderFailure {
  status: 'failed';
  url: string | null;
  reason: 'no_url' | 'fetch' | 'unextractable' | 'empty';
  /** Always null: a failed extraction has no body to lead. */
  readerHero: null;
  originalHero: HeroImageDto | null;
}
```

- [ ] **Step 4: Bump the reader cache version**

In `frontend/src/app/reader/reader-cache.service.ts`, replace the `VERSION`
constant and its comment:

```ts
  // v4: v3 records carry `leadImage` and no resolved heroes (#592), so an
  // already-read article would come back with no picture at all.
  private static readonly VERSION = 4;
```

Replace the v3 comment entirely. It describes a record shape that no longer
exists, and the file keeps no version history.

- [ ] **Step 5: Change the component**

In `frontend/src/app/reader/reader-view/reader-view.component.ts`:

- Delete `import { feedHeroImage } from '../feed-hero-image';`.
- Add `ReaderFailure` and `ReaderContent` to the existing `../models` import.
- Widen the state signal so a failure keeps its payload:

```ts
  private readonly state = signal<
    | { status: 'idle' | 'loading' }
    | { status: 'ok'; article: ReaderArticle }
    | { status: 'failed'; failure: ReaderFailure | null }
  >({ status: 'idle' });
```

- Replace the `leadImage`, `feedHero` and `visibleFeedHero` computeds — the whole
  block from the `// A hero image for articles whose extracted body has none`
  comment down to the `visibleFeedHero` line, keeping `heroError` — with:

```ts
  /** A broken hero URL hides the image rather than leaving a torn placeholder. */
  protected readonly heroError = signal(false);

  /** The payload the heroes come from. Null while loading, and after a
   *  transport error, where no payload arrived at all. */
  private readonly heroSource = computed<ReaderContent | null>(() => {
    const s = this.state();
    if (s.status === 'ok') return s.article;
    if (s.status === 'failed') return s.failure;
    return null;
  });

  /**
   * The picture that leads the article. The backend resolves one hero per body
   * it can serve, so switching between the reader and the original view is a
   * field lookup: no request, and no duplicate-image rule on the client (#592).
   */
  readonly hero = computed(() => {
    if (this.heroError()) return null;
    const source = this.heroSource();
    if (source === null) return null;
    return this.mode() === 'reader' ? source.readerHero : source.originalHero;
  });
```

- In `runLoad()`, carry the failure payload into the state:

```ts
        } else {
          this.state.set({ status: 'failed', failure: c });
          this.readerMode.setOriginalOnly();
        }
      },
      error: () => {
        // A timeout or a transport error leaves no payload, so this article
        // shows the feed's content with no hero.
        this.state.set({ status: 'failed', failure: null });
        this.readerMode.setOriginalOnly();
      },
```

- [ ] **Step 6: Change the template**

In `frontend/src/app/reader/reader-view/reader-view.component.html`, replace the
whole `@if (leadImage(); as img) { … } @else if (visibleFeedHero(); as hero) { … }`
block with:

```html
        @if (hero(); as image) {
          <!-- Width and height carry the aspect ratio, so the article does not
               jump when a slow hero lands. `height: auto` in the stylesheet
               keeps the declared size from overriding the fluid layout. The
               backend picked this picture for the body below it (#592). -->
          <img
            class="lead-image"
            [src]="image.url"
            [attr.width]="image.width"
            [attr.height]="image.height"
            (error)="heroError.set(true)"
            alt=""
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
          />
        }
```

- [ ] **Step 7: Delete the frontend rule**

```bash
cd frontend
git rm src/app/reader/feed-hero-image.ts src/app/reader/feed-hero-image.spec.ts
```

- [ ] **Step 8: Fix the remaining fixtures**

Run: `cd frontend && grep -rn "leadImage" src`
Expected after fixing: no output. Fixtures in
`reader-content.service.spec.ts` and `reader-cache.service.spec.ts` carry
`leadImage: null`; replace it with `readerHero: null, originalHero: null`.

- [ ] **Step 9: Run the reader tests to verify they pass**

Run: `cd frontend && ./node_modules/.bin/jest src/app/reader`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add -A frontend/src
git commit -m "refactor(#592): read the hero from the API and delete the frontend rule"
```

---

### Task 5: Gates

**Files:** whatever the gates require. No new files are expected.

**Interfaces:**
- Consumes: everything from Tasks 1-4.
- Produces: a branch ready for a pull request.

- [ ] **Step 1: Backend style and static analysis**

```bash
cd backend && bin/console cache:warmup && composer check
```

Expected: PSR-12 clean, PHPStan level max clean, phptramp within thresholds.
`composer cs:fix` autofixes style. If phptramp reports a chain, check
`composer show larspohlmann/phptramp` first — CI runs the tip of that tool's
`develop`, so a finding may come from the tool and not from this branch.

- [ ] **Step 2: PHPMD on every touched src file**

```bash
cd backend && composer md
```

Expected: clean. The standing rule is that a touched `src` file must be
PHPMD-clean, not merely free of new findings. Fix the design the metric points
at; do not change a threshold.

- [ ] **Step 3: Full backend suite on both databases**

```bash
cd backend && php bin/phpunit
```

```bash
docker compose exec php vendor/bin/phpunit
```

Expected: PASS on SQLite and on MySQL. Restart the php container first if it
holds stale code.

- [ ] **Step 4: Mutation testing over the changed files**

```bash
cd backend && composer infection:diff
```

Expected: at or above `minMsi` in `infection.json5`. Kill escaped mutants by
adding cases; never lower the threshold. Expect mutants on `imageIdentity()`'s
regexes and on the `??` fallback in `ReaderHeroResolver::readerHero()` — the
resolver test's "suppressed extraction image" case covers the second.

- [ ] **Step 5: Frontend gate**

```bash
cd frontend && npm run check
```

Expected: ESLint, Prettier, Stylelint and Jest all clean.

- [ ] **Step 6: Scan the dev log**

```bash
tail -n 200 backend/var/log/dev.log
```

Expected: no new deprecations or swallowed errors from the reader endpoint.

- [ ] **Step 7: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` over every changed file under `backend/`.
Expected: zero ERROR and zero WARNING. Weak warnings are advisory. If a finding
looks pre-existing, prove it by linting the `develop` version of the same file.

- [ ] **Step 8: Verify the behaviour in the running stack**

Start the stack, open an article whose feed picture also appears in the feed
body, and toggle between Reader and Original. Confirm the picture never shows
twice, that the toggle fires no `/reader` request in the network tab, and that an
article whose extraction fails still shows the feed's picture.

```bash
docker compose up -d
```

- [ ] **Step 9: Commit any fixes and push**

```bash
git push -u origin refactor/592-backend-hero-consolidation
```

---

## Verification against the acceptance criteria

- `frontend/src/app/reader/feed-hero-image.ts` and its spec are deleted (Task 4
  Step 7). `grep -rn "feedHeroImage" frontend/src` returns nothing.
- The rule exists once, in `HeroImageSelector` (Task 1).
- The Reader/Original toggle suppresses duplicates with no new request — proved
  by the component test in Task 4 Step 1 and by hand in Task 5 Step 8.
- A native client reads `readerHero` / `originalHero` and needs no hero logic.
