# Reader: recognise the labrador bot challenge and always offer Retry (#1715) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A mopo.de article fronted by the "labrador" proof-of-work interstitial falls back with "Couldn't reach the page" and a Retry link instead of "The page has no more text than the feed" with no way to try again; and every reader fallback notice, whatever its reason, carries Retry.

**Architecture:** Backend: `LandingChallenge` gains the labrador marker, so `PageLanding::assertNotChallenge()` rejects the 200 interstitial and the extraction reports `fetch` with the detail "bot or consent challenge interstitial", which the frontend already renders with Retry and a "Show error" disclosure. Frontend: the fallback notice always renders the Retry link, in the quiet `empty` variant and in the warning box, since a reason is a verdict the backend may get wrong; `NO_ARTICLE_REASONS` stays only for the audio-first case that shows no note at all.

**Tech Stack:** PHP 8.4 / Symfony 7.4, PHPUnit 12 with `#[DataProvider]`; Angular 20 standalone components with signals, Jest (jsdom), Transloco.

**Spec:** GitHub issue #1715 (`gh issue view 1715`). The user's instruction in chat: "Always give the option to retry."

## Global Constraints

- Markers in `LandingChallenge` are vendor/machine strings, never prose (class docblock).
- No new translation strings: `reader.retry` exists in `en.json` and `de.json`.
- Comments: default to none; one line when a future reader would get it wrong without it.
- Backend gates: `composer check`, `composer md` on touched files, `php bin/phpunit`.
- Frontend: this worktree cannot use the Docker stack, so run `./node_modules/.bin/jest <spec>` and `npm run check` natively from `frontend/`.
- Commits: `fix(#1715): …`, no attribution lines.

## File Structure

| File | Responsibility |
|---|---|
| Modify `backend/src/Service/Reader/LandingChallenge.php` | Marker list gains `/_labrador/pow/` |
| Modify `backend/tests/Service/Reader/LandingChallengeTest.php` | Data-provider case with the real labrador markup |
| Modify `frontend/src/app/reader/article/reader-view/reader-view.component.html` | Retry in the quiet note; Retry in the warning box without the no-article gate |
| Modify `frontend/src/app/reader/article/content/article-source.service.ts` | `NO_ARTICLE_REASONS` docblock no longer claims Retry cannot help |
| Modify `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts` | Retry asserted present for every reason |

---

### Task 1: Recognise the labrador proof-of-work interstitial

**Files:**
- Modify: `backend/src/Service/Reader/LandingChallenge.php`
- Test: `backend/tests/Service/Reader/LandingChallengeTest.php`

**Interfaces:**
- Consumes: `LandingChallenge::matches(string $html): bool`
- Produces: nothing new; the `fetch` reason with detail `<url>: bot or consent challenge interstitial` already flows from `PageLanding::assertNotChallenge()`.

- [ ] **Step 1: Add the failing data-provider case**

In `challengeBodies()` append:

```php
        yield 'labrador proof of work' => [
            '<title>Are we human?</title><script defer type="text/javascript" src="/_labrador/pow/slow.js"></script>',
        ];
```

- [ ] **Step 2: Run it to see it fail**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/LandingChallengeTest.php`
Expected: 1 failure, `testRecognisesAChallengeBody with data set "labrador proof of work"`.

- [ ] **Step 3: Add the marker**

In `LandingChallenge::MARKERS` append `'/_labrador/pow/',`.

- [ ] **Step 4: Run the test and the gates**

Run from `backend/`: `php bin/phpunit tests/Service/Reader/LandingChallengeTest.php` → all pass.
Run `composer check` and `vendor/bin/phpmd src/Service/Reader/LandingChallenge.php text phpmd.xml.dist` (or `composer md`) → clean.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/LandingChallenge.php backend/tests/Service/Reader/LandingChallengeTest.php
git commit -m "fix(#1715): recognise the labrador proof-of-work interstitial as a landing challenge"
```

---

### Task 2: Every fallback notice offers Retry

**Files:**
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html:130-150`
- Modify: `frontend/src/app/reader/article/content/article-source.service.ts:11-17`
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts:1443-1471`

**Interfaces:**
- Consumes: `ArticleSource.failureReason`, `ArticleSource.errorDetail`, `ReaderViewComponent.refreshArticle()`.
- Produces: `.note-link` present inside `.reader-fallback-quiet` and inside `.reader-fallback` for every reason.

- [ ] **Step 1: Flip the spec**

Replace the `it.each` at line 1443 ("offers no Retry and no error disclosure when the page holds no article") with:

```ts
    it.each(['unextractable', 'mismatch'] as const)(
      'offers Retry but no error disclosure when the page holds no article (%s)',
      (reason) => {
        loadMock.mockReturnValue(of<ReaderContent>(aReaderFailure({ reason, detail: null })));
        const element = mount(entry()).nativeElement as HTMLElement;

        expect(element.querySelector('.reader-fallback .note-link')).not.toBeNull();
        expect(element.querySelector('.reader-error')).toBeNull();
      },
    );

    it('shows no note at all for a player page', () => {
      loadMock.mockReturnValue(
        of<ReaderContent>(aReaderFailure({ reason: 'player_page', detail: null })),
      );
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.reader-fallback, .reader-fallback-quiet')).toBeNull();
    });
```

In the test "notes a page no longer than the feed quietly, outside the warning box" add after the text assertion:

```ts
      expect(element.querySelector('.reader-fallback-quiet .note-link')).not.toBeNull();
```

- [ ] **Step 2: Run the spec to see it fail**

Run from `frontend/`: `./node_modules/.bin/jest src/app/reader/article/reader-view/reader-view.component.spec.ts -t "reader fallback"`
Expected: the three flipped cases fail on a missing `.note-link`.

- [ ] **Step 3: Render Retry in both notice variants**

In `reader-view.component.html` replace the `@if (fallbackNoticeShown())` block's two variants:

```html
        @if (fallbackNoticeShown()) {
          @if (source.failureReason() === 'empty') {
            <p class="reader-fallback-quiet">
              {{ fallbackMessageKey() | transloco }}
              <button type="button" class="note-link reads-as-text" (click)="refreshArticle()">
                {{ 'reader.retry' | transloco }}
              </button>
            </p>
          } @else {
            <app-warning-box class="reader-fallback">
              <span class="warning-glyph" aria-hidden="true">⚠</span>
              <div class="warning-text">
                <p class="reader-note">
                  {{ fallbackMessageKey() | transloco }}
                  <button type="button" class="note-link reads-as-text" (click)="refreshArticle()">
                    {{ 'reader.retry' | transloco }}
                  </button>
                </p>
                @if (source.errorDetail(); as detail) {
                  <details class="reader-error">
                    <summary>{{ 'reader.showError' | transloco }}</summary>
                    <pre>{{ detail }}</pre>
                  </details>
                }
              </div>
            </app-warning-box>
          }
        }
```

In `article-source.service.ts` change the docblock of `NO_ARTICLE_REASONS` to:

```ts
/** Failures that say the page holds no article; an audio-first entry shows no note for them. */
```

- [ ] **Step 4: Run the spec and the gate**

Run from `frontend/`: `./node_modules/.bin/jest src/app/reader/article/reader-view/reader-view.component.spec.ts` → all pass.
Run `npm run check` → clean (Prettier may want the long `<button>` lines wrapped; accept its formatting).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/article/reader-view/reader-view.component.html frontend/src/app/reader/article/content/article-source.service.ts frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts
git commit -m "fix(#1715): offer Retry in every reader fallback notice"
```

---

### Task 3: Verify and open the PR

- [ ] **Step 1: Full backend suite** — from `backend/`: `php bin/phpunit` → green.
- [ ] **Step 2: Mutation gate** — from `backend/`: `composer infection:diff` → `minMsi` met.
- [ ] **Step 3: Push and open the PR** into `develop`, body with `Closes #1715`, listing what the worktree could not verify: the live render on the Docker stack and the e2e suites.
