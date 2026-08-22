# Feed preview rendered like the reader — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the add-feed dialog's sample entries as inert reader-style rows, driven by an enriched preview payload, so a user sees what a feed carries before subscribing.

**Architecture:** The backend widens each preview item from a shape-summary to plain reader-entry data (title, url, author, plain-text snippet, https image URL + dimensions, publishedAt). The frontend renders those through a **new, decoupled** `app-preview-entry-row` component — a visual copy of the reader row that is inert by construction (no actions, no navigation) and never touches the shared `app-entry-row`. The dialog expands one candidate at a time and previews it lazily; it widens to 520px on desktop and goes full-screen on phones.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend), Angular 20 standalone + signals (frontend), PHPUnit, Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-22-519-feed-preview-render-design.md`

## Global Constraints

- **PHP:** `declare(strict_types=1)` in every file; PSR-12 (`composer cs:fix`); PHPStan level max (`composer stan`, needs `bin/console cache:warmup` first); PHPMD codesize clean on every touched `src` file (`composer md`); `final readonly` value objects with constructor promotion; typed namespaced exceptions; controllers stay thin (no private methods carrying responsibility). Full gate: `composer check`.
- **Frontend:** standalone components + signals, no NgModules; component styles in a sibling `.scss` via `styleUrl` (never inline); no hex colours or raw `px` spacing outside `theme/` (Stylelint); Prettier 100-column. Full gate from `frontend/`: `npm run check`.
- **TDD:** write the failing test first, watch it fail, implement minimally, watch it pass, commit. One deliverable per task.
- **Mutation gate:** CI runs `composer infection:diff` over changed backend files; keep new logic covered.
- **Native iOS:** the preview payload stays plain JSON — a data shape, not a browser-only contract.
- **Branch:** `feature/519-feed-preview-render`, off `develop`. Commit per task. Do not merge unasked.
- **Verify containers are current** before diagnosing any runtime behaviour (worker/DI/frontend chunk can go stale mid-branch).

## Branch setup

- [ ] Create the branch off `develop`:

```bash
git checkout develop && git pull && git checkout -b feature/519-feed-preview-render
```

## File Structure

**Backend**
- Modify `backend/src/Service/Preview/FeedPreviewItem.php` — the preview item value object, reshaped to reader-entry fields.
- Modify `backend/src/Service/Preview/FeedPreviewService.php` — the item mapping, the sample-size cap.
- Modify `backend/src/Http/FeedPreviewJson.php` — serialize the new item fields.
- Modify `backend/tests/Service/Preview/FeedPreviewServiceTest.php` and `backend/tests/Controller/Api/FeedPreviewControllerTest.php`.

**Frontend**
- Modify `frontend/src/app/reader/models.ts` — the `FeedPreviewItem` interface.
- Create `frontend/src/app/reader/add-feed/preview-entry-row/preview-entry-row.component.{ts,html,scss,spec.ts}` — the decoupled inert row.
- Modify `frontend/src/app/reader/add-feed/add-feed-dialog.component.{ts,html,scss}` and `...spec.ts` — expand-on-select, lazy preview, render rows, widen + fillOnMobile.
- Modify `docs/design-language.md` — record the 520px add-feed variant and its `fillOnMobile`.
- Modify `frontend/e2e/add-feed-mobile.spec.ts` — new stub shape, keep phone assertions.

---

### Task 1: Enrich the preview item and its mapping (backend)

**Files:**
- Modify: `backend/src/Service/Preview/FeedPreviewItem.php`
- Modify: `backend/src/Service/Preview/FeedPreviewService.php:27` (sample cap) and `:99-111` (`item()`)
- Test: `backend/tests/Service/Preview/FeedPreviewServiceTest.php`

**Interfaces:**
- Consumes: `ParsedEntry { string $title; ?string $url; ?string $author; ?string $summary; ?string $contentHtml; ?\DateTimeImmutable $publishedAt; ?ParsedImage $image }`, `ParsedImage { string $url; ?int $width; ?int $height }`, `EntrySnippet::from(?string): ?string` (the ingest snippet helper).
- Produces: `FeedPreviewItem { string $title; ?string $url; ?string $author; ?string $summary; ?string $imageUrl; ?int $imageWidth; ?int $imageHeight; ?\DateTimeImmutable $publishedAt }`, and `FeedPreviewService::SAMPLE_SIZE === 8`.

- [ ] **Step 1: Update the service test for the new item shape and cap**

In `backend/tests/Service/Preview/FeedPreviewServiceTest.php`:
- In `testFullTextFeedYieldsFullVerdictAndCapsItemsAtFour`: rename to `testFullTextFeedYieldsFullVerdictAndCapsItemsAtEight`, seed **9** items, and assert `assertCount(8, $preview->items)` (was 4).
- Replace `testItemWithMediaImageMarksHasImages`'s per-item `hasImage` assertions with image-URL assertions. Give the first sample entry an **https** media image `https://img.example/a.jpg` (width 800, height 600) and the second none, then assert:

```php
self::assertTrue($preview->hasImages);
self::assertSame('https://img.example/a.jpg', $preview->items[0]->imageUrl);
self::assertSame(800, $preview->items[0]->imageWidth);
self::assertSame(600, $preview->items[0]->imageHeight);
self::assertNull($preview->items[1]->imageUrl);
```

- Add a case that an **http** image is dropped (URL and dimensions both null):

```php
public function testHttpImageIsDroppedFromPreviewItem(): void
{
    // Arrange a single-item feed whose entry image URL is http://… (see the
    // existing media-image fixture builder in this file) and preview it.
    $preview = $this->service->preview($this->user, 'https://feed.example/http-image');

    self::assertNull($preview->items[0]->imageUrl);
    self::assertNull($preview->items[0]->imageWidth);
    self::assertNull($preview->items[0]->imageHeight);
}
```

- In `testTitlesOnlyFeedYieldsTitleOnlyVerdict`: replace the `textLength === 0` / `snippet === ''` assertions with `self::assertNull($preview->items[0]->summary);` (or `''` — match what `EntrySnippet::from(null)` returns; see step 3).
- In `testSnippetIsTruncatedOnWordBoundary...`: assert against `$preview->items[0]->summary` instead of `->snippet`.

Follow the existing fixture-builder helpers in this file (the `StubFeedFetcher` payloads) for shape; do not invent a new harness.

- [ ] **Step 2: Run the service test to verify it fails**

Run: `cd backend && php bin/phpunit --filter FeedPreviewServiceTest`
Expected: FAIL — `FeedPreviewItem` has no `imageUrl`/`summary`, and the count is 4 not 8.

- [ ] **Step 3: Reshape `FeedPreviewItem`**

Replace the class body of `backend/src/Service/Preview/FeedPreviewItem.php`:

```php
final readonly class FeedPreviewItem
{
    public function __construct(
        public string $title,
        public ?string $url,
        public ?string $author,
        public ?string $summary,
        public ?string $imageUrl,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public ?\DateTimeImmutable $publishedAt,
    ) {
    }
}
```

- [ ] **Step 4: Raise the sample cap and rewrite `item()`**

In `backend/src/Service/Preview/FeedPreviewService.php`:
- Line 27: `private const int SAMPLE_SIZE = 8;`
- Replace `item()` (lines 99-111) and add the image helper:

```php
private function item(ParsedEntry $entry): FeedPreviewItem
{
    $imageUrl = $this->httpsImageUrl($entry->image);

    return new FeedPreviewItem(
        title: $entry->title,
        url: $entry->url,
        author: $entry->author,
        summary: EntrySnippet::from($entry->summary ?? $entry->contentHtml),
        imageUrl: $imageUrl,
        imageWidth: $imageUrl === null ? null : $entry->image?->width,
        imageHeight: $imageUrl === null ? null : $entry->image?->height,
        publishedAt: $entry->publishedAt,
    );
}

// The SPA is https, so an http/relative/data image is useless in an <img>.
// Mirrors the reader's firstPreviewImage rule.
private function httpsImageUrl(?ParsedImage $image): ?string
{
    if ($image === null) {
        return null;
    }

    return str_starts_with($image->url, 'https://') ? $image->url : null;
}
```

Add the `use` for `EntrySnippet` (`App\Service\Ingest\EntrySnippet`) and `ParsedImage` (`App\Service\Parser\ParsedImage`). Delete the now-unused `plainText()`/`snippet()`/`SNIPPET_LEN` **only if** nothing else references them — the content-tier `tier()`/`verdict()` uses its own plain-text path (`PlainText::from`), so confirm with a grep before deleting; if `plainText()` still feeds `tier()`, leave it.

- [ ] **Step 5: Run the service test to verify it passes**

Run: `cd backend && php bin/phpunit --filter FeedPreviewServiceTest`
Expected: PASS.

- [ ] **Step 6: Lint and commit**

```bash
cd backend && composer cs:fix && bin/console cache:warmup && composer stan && composer md
git add backend/src/Service/Preview/FeedPreviewItem.php backend/src/Service/Preview/FeedPreviewService.php backend/tests/Service/Preview/FeedPreviewServiceTest.php
git commit -m "feat(#519): enrich preview items to reader-entry fields, sample 8"
```

---

### Task 2: Serialize the enriched item (backend)

**Files:**
- Modify: `backend/src/Http/FeedPreviewJson.php`
- Test: `backend/tests/Controller/Api/FeedPreviewControllerTest.php`

**Interfaces:**
- Consumes: `FeedPreviewItem` (Task 1).
- Produces: each item JSON object with keys `title, url, author, summary, imageUrl, imageWidth, imageHeight, publishedAt`.

- [ ] **Step 1: Update the controller JSON-shape test**

In `backend/tests/Controller/Api/FeedPreviewControllerTest.php`, in `testAuthenticatedValidUrlReturnsPreviewJson` (line 108), replace the per-item key assertions (`hasImage`, `textLength`, `snippet`) with the new shape. Keep the feed-level keys (`title`, `itemCount`, `content`, `hasImages`). For the first item assert, e.g.:

```php
$item = $data['feed']['items'][0];
self::assertSame(['title', 'url', 'author', 'summary', 'imageUrl', 'imageWidth', 'imageHeight', 'publishedAt'], array_keys($item));
self::assertSame('A. Writer', $item['author']);
self::assertArrayHasKey('imageUrl', $item);
```

Match the fixture the test already feeds the stub fetcher; if that fixture's image is http or absent, `imageUrl` will be null — assert accordingly, or give the fixture an https image and assert the URL.

- [ ] **Step 2: Run the controller test to verify it fails**

Run: `cd backend && php bin/phpunit --filter FeedPreviewControllerTest::testAuthenticatedValidUrlReturnsPreviewJson`
Expected: FAIL — old keys `hasImage`/`textLength`/`snippet` still emitted.

- [ ] **Step 3: Update `FeedPreviewJson`**

In `backend/src/Http/FeedPreviewJson.php`, replace the per-item `array_map` closure:

```php
'items' => array_map(
    static fn (FeedPreviewItem $i) => [
        'title' => $i->title,
        'url' => $i->url,
        'author' => $i->author,
        'summary' => $i->summary,
        'imageUrl' => $i->imageUrl,
        'imageWidth' => $i->imageWidth,
        'imageHeight' => $i->imageHeight,
        'publishedAt' => $i->publishedAt?->format(\DateTimeInterface::ATOM),
    ],
    $preview->items,
),
```

Update the method's `@return` docblock array shape to match (PHPStan max will flag a stale shape).

- [ ] **Step 4: Run the controller test to verify it passes**

Run: `cd backend && php bin/phpunit --filter FeedPreviewControllerTest`
Expected: PASS.

- [ ] **Step 5: Full backend gate and commit**

```bash
cd backend && composer cs:fix && bin/console cache:warmup && composer check && php bin/phpunit
git add backend/src/Http/FeedPreviewJson.php backend/tests/Controller/Api/FeedPreviewControllerTest.php
git commit -m "feat(#519): serialize enriched preview items"
```

---

### Task 3: Frontend preview-item model

**Files:**
- Modify: `frontend/src/app/reader/models.ts:138-154`

**Interfaces:**
- Produces: `FeedPreviewItem { title: string; url: string | null; author: string | null; summary: string | null; imageUrl: string | null; imageWidth: number | null; imageHeight: number | null; publishedAt: string | null }`, and `FeedPreview.items: FeedPreviewItem[]` (unchanged element-type name).

- [ ] **Step 1: Replace the `FeedPreviewItem` interface**

In `frontend/src/app/reader/models.ts`, replace the `FeedPreviewItem` interface (lines 138-146) with:

```ts
export interface FeedPreviewItem {
  title: string;
  url: string | null;
  author: string | null;
  summary: string | null;
  imageUrl: string | null;
  imageWidth: number | null;
  imageHeight: number | null;
  publishedAt: string | null;
}
```

Leave `FeedPreview` as is — its `items: FeedPreviewItem[]` now carries the richer shape.

- [ ] **Step 2: Type-check**

Run: `cd frontend && npx tsc --noEmit`
Expected: the dialog's old `.samples` template reads (`it.title`) still type-check; no errors from the model change alone.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/models.ts
git commit -m "feat(#519): widen the FeedPreviewItem model to reader-entry fields"
```

---

### Task 4: The decoupled `app-preview-entry-row` component

**Files:**
- Create: `frontend/src/app/reader/add-feed/preview-entry-row/preview-entry-row.component.ts`
- Create: `frontend/src/app/reader/add-feed/preview-entry-row/preview-entry-row.component.html`
- Create: `frontend/src/app/reader/add-feed/preview-entry-row/preview-entry-row.component.scss`
- Test: `frontend/src/app/reader/add-feed/preview-entry-row/preview-entry-row.component.spec.ts`

**Interfaces:**
- Consumes: `FeedPreviewItem` (Task 3), `FaviconComponent` (`app-favicon`, inputs `url`, `size`), `relativeTime(iso, lang)` from `../../format`, `LanguageService`.
- Produces: `<app-preview-entry-row [item] [source] [faviconUrl]?>` — required `item`, string `source` (default `''`), `faviconUrl` (default `null`). No outputs.

- [ ] **Step 1: Write the failing spec**

Create `preview-entry-row.component.spec.ts`:

```ts
import { ComponentRef } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { PreviewEntryRowComponent } from './preview-entry-row.component';
import { FeedPreviewItem } from '../../models';

function item(over: Partial<FeedPreviewItem> = {}): FeedPreviewItem {
  return {
    title: 'A sample headline',
    url: 'https://example.com/a',
    author: null,
    summary: 'A short snippet of the article body.',
    imageUrl: 'https://img.example/a.jpg',
    imageWidth: 800,
    imageHeight: 600,
    publishedAt: '2026-08-20T10:00:00+00:00',
    ...over,
  };
}

describe('PreviewEntryRowComponent', () => {
  let fixture: ComponentFixture<PreviewEntryRowComponent>;
  let ref: ComponentRef<PreviewEntryRowComponent>;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [PreviewEntryRowComponent] });
    fixture = TestBed.createComponent(PreviewEntryRowComponent);
    ref = fixture.componentRef;
    ref.setInput('item', item());
    ref.setInput('source', 'The Verge');
  });

  it('renders title, source, snippet and the https thumbnail', () => {
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('A sample headline');
    expect(el.querySelector('.meta')!.textContent).toContain('The Verge');
    expect(el.querySelector('.snippet')!.textContent).toContain('A short snippet');
    expect(el.querySelector('img.thumb')!.getAttribute('src')).toBe('https://img.example/a.jpg');
  });

  it('omits the thumbnail when there is no image', () => {
    ref.setInput('item', item({ imageUrl: null, imageWidth: null, imageHeight: null }));
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('img.thumb')).toBeNull();
  });

  it('is inert: no button role and no action buttons', () => {
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('[role="button"]')).toBeNull();
    expect(el.querySelector('app-entry-actions')).toBeNull();
    expect(el.querySelector('button')).toBeNull();
  });
});
```

- [ ] **Step 2: Run the spec to verify it fails**

Run: `cd frontend && npx jest preview-entry-row`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Write the component**

`preview-entry-row.component.ts`:

```ts
import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { LanguageService } from '../../../core/language.service';
import { relativeTime } from '../../format';
import { FeedPreviewItem } from '../../models';

// A visual copy of the reader entry row, deliberately decoupled from
// app-entry-row (#519): inert by construction — no click target, no actions,
// no tags, no read dot — so the preview never entangles the reader's row.
@Component({
  selector: 'app-preview-entry-row',
  imports: [FaviconComponent],
  templateUrl: './preview-entry-row.component.html',
  styleUrl: './preview-entry-row.component.scss',
})
export class PreviewEntryRowComponent {
  readonly item = input.required<FeedPreviewItem>();
  readonly source = input<string>('');
  readonly faviconUrl = input<string | null>(null);
  readonly imgError = signal(false);

  private readonly language = inject(LanguageService);
  readonly when = computed(() => {
    const at = this.item().publishedAt;
    return at ? relativeTime(at, this.language.lang()) : '';
  });

  // Reset the failed-image flag when the row is reused for another item.
  private readonly _resetOnItemChange = effect(() => {
    this.item();
    this.imgError.set(false);
  });
}
```

`preview-entry-row.component.html`:

```html
<article class="prow">
  <div class="body">
    <h3 class="title">{{ item().title }}</h3>
    <p class="meta">
      <app-favicon [url]="faviconUrl()" [size]="14" />{{ source() }}@if (when()) { · {{ when() }}}
    </p>
    @if (item().summary) {
      <p class="snippet">{{ item().summary }}</p>
    }
  </div>
  @if (item().imageUrl && !imgError()) {
    <img
      class="thumb"
      [src]="item().imageUrl"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
  }
</article>
```

`preview-entry-row.component.scss` (mirrors the reader row's measures; tokens only):

```scss
.prow {
  display: flex;
  gap: var(--space-3);
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
  border-bottom: 1px solid var(--border);
}

.prow:last-child {
  border-bottom: 0;
}

.body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.title {
  margin: 0;
  font-size: var(--fs-base);
  font-weight: 500;
  color: var(--text-primary);
}

.meta {
  margin: 0;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.snippet {
  margin: 0;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  display: -webkit-box;
  -webkit-line-clamp: 4;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.thumb {
  width: 88px;
  height: 66px;
  object-fit: cover;
  border-radius: var(--radius);
  flex: 0 0 auto;
}
```

- [ ] **Step 4: Run the spec to verify it passes**

Run: `cd frontend && npx jest preview-entry-row`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx prettier --write "src/app/reader/add-feed/preview-entry-row/**" && npx stylelint "src/app/reader/add-feed/preview-entry-row/**/*.scss"
git add frontend/src/app/reader/add-feed/preview-entry-row
git commit -m "feat(#519): add the decoupled inert app-preview-entry-row"
```

---

### Task 5: Dialog — expand-on-select, lazy preview, render rows, widen + fillOnMobile

**Files:**
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.html`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.scss:9-13`
- Test: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

**Interfaces:**
- Consumes: `PreviewEntryRowComponent` (Task 4), `ReaderApi.previewFeed(url, format?)`, the existing `previews`/`candidates` signals and `okPreview`/`storedFormat`/`parseProblem`.
- Produces: an `expanded` signal, `toggle(c)`, `ensurePreview(c)`; the dialog renders `<app-preview-entry-row>` for the expanded candidate only.

- [ ] **Step 1: Update the dialog spec for lazy expand-on-select**

In `add-feed-dialog.component.spec.ts`, rewrite the central preview test `lists candidates as cards with previews and subscribes via the Subscribe button` into two tests. Follow the file's existing harness (its `ReaderApi` stub and `subscribe`/`previewFeed` spies):

```ts
it('auto-expands and previews a single candidate', () => {
  // discovery returns ONE candidate → the dialog expands it and previews it once.
  // (drive submit() so the api returns a single-candidate SubscribeResult, per the
  // existing candidate-returning helper in this file)
  ...trigger discovery with one candidate...
  expect(api.previewFeed).toHaveBeenCalledTimes(1);
  const rows = fixture.nativeElement.querySelectorAll('app-preview-entry-row');
  expect(rows.length).toBeGreaterThan(0);
});

it('previews a candidate lazily only when it is expanded', () => {
  // discovery returns TWO candidates → nothing is previewed until one is clicked.
  ...trigger discovery with two candidates...
  expect(api.previewFeed).not.toHaveBeenCalled();

  // click the first candidate's header to expand it
  const head = fixture.nativeElement.querySelector('.card .card-head') as HTMLButtonElement;
  head.click();
  fixture.detectChanges();

  expect(api.previewFeed).toHaveBeenCalledTimes(1);
  expect(fixture.nativeElement.querySelectorAll('app-preview-entry-row').length).toBeGreaterThan(0);
});
```

Then update the sibling preview-assertion tests so each still passes under lazy previews:
- `labels a scraped candidate and subscribes/previews with the scraped format` and `labels a wp-json candidate ... passes the format to preview` — if the scenario returns a **single** candidate it auto-expands, so the `previewFeed` spy is called with the format automatically; if it returns multiple, add a `head.click()` on the target candidate before asserting `previewFeed` was called with the format. Read each test and apply whichever the scenario needs.
- `shows the backend's problem detail when a preview fails` / `falls back to a generic line ...` — the failing `previewFeed` must now be reached via auto-expand (single candidate) or a `head.click()`.

- [ ] **Step 2: Run the dialog spec to verify it fails**

Run: `cd frontend && npx jest add-feed-dialog`
Expected: FAIL — `expanded`/`toggle` and `app-preview-entry-row` do not exist; eager `loadPreviews` still fires.

- [ ] **Step 3: Rework the component TypeScript**

In `add-feed-dialog.component.ts`:
- Add `PreviewEntryRowComponent` to `imports` (import from `./preview-entry-row/preview-entry-row.component`).
- Add the expanded signal near the other signals:

```ts
readonly expanded = signal<string | null>(null);
```

- Replace `loadPreviews(candidates)` (the eager per-candidate fetch) with a lazy pair:

```ts
toggle(candidate: FeedCandidate): void {
  if (this.expanded() === candidate.url) {
    this.expanded.set(null);
    return;
  }
  this.expanded.set(candidate.url);
  this.ensurePreview(candidate);
}

private ensurePreview(candidate: FeedCandidate): void {
  if (this.previews()[candidate.url]) {
    return;
  }
  this.previews.update((m) => ({ ...m, [candidate.url]: { status: 'loading' } }));
  this.api.previewFeed(candidate.url, this.storedFormat(candidate)).subscribe({
    next: (r) =>
      this.previews.update((m) => ({ ...m, [candidate.url]: { status: 'ok', preview: r.feed } })),
    error: (e) =>
      this.previews.update((m) => ({
        ...m,
        [candidate.url]: { status: 'error', message: parseProblem(e).detail },
      })),
  });
}
```

- In `subscribe(...)`, where it currently sets `candidates` and calls `loadPreviews(res.candidates)`: set candidates, reset `previews`/`expanded`, and auto-expand a lone candidate:

```ts
this.candidates.set(res.candidates);
this.previews.set({});
this.expanded.set(null);
if (res.candidates.length === 1) {
  this.toggle(res.candidates[0]);
}
```

(Keep the rest of the `subscribe` branches — the closed-with-subscription and `failureReason` paths — unchanged.)

- [ ] **Step 4: Rework the template**

In `add-feed-dialog.component.html`:
- Add `fillOnMobile` to the panel: `<app-overlay-panel [heading]="'dialog.addFeed.title' | transloco" fillOnMobile>`.
- Replace the candidate `<li class="card">` body. Make the head a toggle button and swap the `<ul class="samples">` bullets for the preview rows of the expanded candidate:

```html
<li class="card" [class.expanded]="expanded() === c.url">
  <button type="button" class="card-head" (click)="toggle(c)" [attr.aria-expanded]="expanded() === c.url">
    <span class="card-title">{{ okPreview(previews()[c.url])?.title || c.title || c.url }}</span>
    @if (okPreview(previews()[c.url]); as p) {
      <span class="count">{{ 'dialog.addFeed.items' | transloco: { count: p.itemCount } }}</span>
    }
  </button>
  <div class="badges">
    <span class="badge format">{{ formatLabel(c.format) }}</span>
    @if (okPreview(previews()[c.url]); as p) {
      <span class="badge">{{ contentLabel(p.content) }}</span>
      <span class="badge">{{ (p.hasImages ? 'dialog.addFeed.withImages' : 'dialog.addFeed.noImages') | transloco }}</span>
    }
    @if (c.format === 'scraped') {
      <span class="badge experimental">{{ 'dialog.addFeed.experimental' | transloco }}</span>
    }
  </div>
  @if (c.format === 'scraped') {
    <p class="muted scraped-hint">{{ 'dialog.addFeed.scrapedHint' | transloco }}</p>
  }
  @if (expanded() === c.url) {
    @let state = previews()[c.url];
    @if (state?.status === 'loading') {
      <p class="muted">{{ 'dialog.addFeed.loadingPreview' | transloco }}</p>
    } @else if (state?.status === 'error') {
      <p class="muted">{{ errorMessage(state) }}</p>
    } @else if (okPreview(state); as p) {
      @if (p.items.length) {
        <div class="preview-rows">
          @for (it of p.items; track $index) {
            <app-preview-entry-row [item]="it" [source]="p.title || c.title || c.url" />
          }
        </div>
      } @else {
        <p class="muted">{{ 'dialog.addFeed.noRecentItems' | transloco }}</p>
      }
    }
  }
  <button type="button" class="subscribe primary" (click)="pick(c)">
    {{ 'dialog.addFeed.subscribe' | transloco }}
  </button>
</li>
```

(Preserve the existing `errorMessage(state)` helper usage exactly as the current template calls it.)

- [ ] **Step 5: Update the SCSS**

In `add-feed-dialog.component.scss`:
- Change the width on `:host` (line 12): `--panel-w: 520px;`
- Make `.card-head` a bare button and add the rows wrapper. Replace the `.samples` block with:

```scss
.card-head {
  /* A full-width toggle: expands the candidate's preview. */
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-2);
  width: 100%;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.preview-rows {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}
```

Delete the old `.samples` rule.

- [ ] **Step 6: Run the dialog spec to verify it passes**

Run: `cd frontend && npx jest add-feed-dialog`
Expected: PASS. Iterate on the sibling tests (Step 1) until green.

- [ ] **Step 7: Full frontend gate and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/reader/add-feed/add-feed-dialog.component.ts frontend/src/app/reader/add-feed/add-feed-dialog.component.html frontend/src/app/reader/add-feed/add-feed-dialog.component.scss frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts
git commit -m "feat(#519): render preview rows, expand-on-select, widen dialog + fillOnMobile"
```

---

### Task 6: Record the dialog variant in the design language

**Files:**
- Modify: `docs/design-language.md` (the `<app-overlay-panel>` section, around lines 350-396)

- [ ] **Step 1: Add the variant note**

In the overlay-panel section of `docs/design-language.md`, add a line recording the new add-feed variant and its mobile behaviour, in the doc's existing voice:

```markdown
The add-feed dialog is the wide variant: `--panel-w: 520px`, sized so a preview
row's 88×66 thumbnail and four-line snippet sit comfortably. It sets
`fillOnMobile`, so on a phone it becomes the full screen rather than a 92vw card
— a card would squeeze the row's title to ~125px. This is the first non-default
`--panel-w`; keep new panels on the 460px default unless their content needs the
room, and record the exception here.
```

- [ ] **Step 2: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#519): record the 520px add-feed panel variant and fillOnMobile"
```

---

### Task 7: Update the mobile e2e for the new shape and full-screen

**Files:**
- Modify: `frontend/e2e/add-feed-mobile.spec.ts`

**Interfaces:**
- Consumes: the `**/api/feeds/preview` route stub (around line 49) and the phone assertions (box fits, body scrolls).

- [ ] **Step 1: Update the preview stub payload to the new item shape**

In `add-feed-mobile.spec.ts`, change the stubbed `/api/feeds/preview` fulfil body so each item uses the enriched shape (the spec owns this data — do not read the seeded account):

```ts
await route.fulfill({
  json: {
    feed: {
      title: 'The Verge',
      itemCount: 40,
      content: 'summary',
      hasImages: true,
      items: [
        {
          title: 'A sample headline',
          url: 'https://example.com/a',
          author: null,
          summary: 'A short snippet of the article body.',
          imageUrl: 'https://img.example/a.jpg',
          imageWidth: 800,
          imageHeight: 600,
          publishedAt: '2026-08-20T10:00:00+00:00',
        },
      ],
    },
  },
});
```

- [ ] **Step 2: Keep the panel-fits-phone and body-scrolls assertions, add a preview-row check**

The dialog now uses `fillOnMobile`, so the panel is full-screen: the existing `box.height <= PHONE.height` assertion (≈ line 111) still holds (equality), and `scrollHeight > clientHeight` (≈ line 124) still holds because 8 rows overflow. Where a candidate is expanded, assert a preview row appears:

```ts
// after expanding the (single, auto-expanded) candidate
await expect(page.locator('app-preview-entry-row').first()).toBeVisible();
```

If the fixture returns multiple candidates, click a `.card-head` to expand first. Keep the "every candidate can be subscribed, including the last one" assertions unchanged.

- [ ] **Step 3: Run the e2e (needs the Docker stack up)**

Run: `cd frontend && npm run e2e -- add-feed-mobile`
Expected: PASS. (Bring the stack up with `docker compose up -d` from the repo root if needed.)

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/add-feed-mobile.spec.ts
git commit -m "test(#519): mobile e2e for enriched preview rows and full-screen dialog"
```

---

## Final verification

- [ ] Backend full gate: `cd backend && bin/console cache:warmup && composer check && php bin/phpunit`
- [ ] Backend mutation on the diff: `cd backend && composer infection:diff`
- [ ] Frontend full gate: `cd frontend && npm run check`
- [ ] Manual visual check: run the app, open Add feed, enter a real feed URL (e.g. a Verge/Ars feed), confirm the preview rows render like the reader row on desktop, and confirm the dialog is full-screen on a 375px viewport with the rows readable and the body scrolling.
- [ ] Scan `backend/var/log/dev.log` for deprecations or swallowed errors after backend runs.
- [ ] Open the PR into `develop` with `Closes #519`; after merge, verify the issue closed.

## Self-review notes (author)

- Spec coverage: decision 1 (row look) → Task 4; decision 2 (decoupled duplicate) → Task 4 (new component, shared row untouched); decision 3 (expand-on-select, lazy) → Task 5; decision 4 (sample 8, 520px) → Tasks 1 + 5; decision 5 (fillOnMobile) → Tasks 5 + 6 + 7; backend enrichment → Tasks 1 + 2.
- Type consistency: `FeedPreviewItem` fields are identical in `FeedPreviewItem.php` (Task 1), `FeedPreviewJson` keys (Task 2) and the TS interface (Task 3): `title, url, author, summary, imageUrl, imageWidth, imageHeight, publishedAt`.
- Open confirmation for the executor: `EntrySnippet::from` signature (nullable in/out) — confirm at `backend/src/Service/Ingest/EntrySnippet.php` and mirror its null-handling in the Task 1 assertion. Confirm whether `plainText()` in `FeedPreviewService` still feeds `tier()` before deleting it.
