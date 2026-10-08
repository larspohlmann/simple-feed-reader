# Reader Fallback Notice Per Reason Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the reader falls back to the feed's body, say *why* in words chosen by the failure reason, offer Retry only where it can help, and show "Show error" only when there is a real cause — never the bare wire code (`empty`).

**Architecture:** `ArticleSource` exposes the backend failure reason (`null` for a browser-side transport failure) and stops falling back to the reason code in `errorDetail`. `ReaderViewComponent` picks the notice text by reason through `reader.fallback.<reason>` translation keys, gates Retry on `!pageHoldsNoArticle()`, and renders `empty` as a quiet muted line instead of the amber warning box.

**Tech Stack:** Angular 20 (standalone, signals), Transloco, Jest.

**Spec:** GitHub issue #1444 (the table in its body is the design).

## Global Constraints

- Frontend only; the backend already sends `reason` and `detail`.
- en and de dictionaries keep identical keys (`i18n-dictionaries.spec.ts`).
- Prettier 100 columns; no hex or raw `px` in `.scss` (tokens only).
- Commits: `type(#1444): lower-case summary`.
- Tests run with `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" <path>` inside the worktree (the path breaks `npx jest`); the full gate is `npm run check` + `npm run build`.

| Reason | Notice (en) | Retry | Show error |
|---|---|---|---|
| transport (no payload) | Couldn't load the full article — showing the feed's summary. | yes | yes (HTTP message) |
| `fetch` | Couldn't reach the page — showing the feed's summary. | yes | when `detail` is set |
| `no_url` | This entry links to no page — showing the feed's summary. | yes (unreachable in practice: `open()` never loads a URL-less entry) | never |
| `unextractable` | Couldn't find an article on this page — showing the feed's summary. | no | never |
| `empty` | The page has no more text than the feed — showing the feed's version. (quiet line) | no | never |
| `mismatch` | The page didn't match this entry — showing the feed's summary. | no | never |

---

### Task 1: Per-reason fallback notice

**Files:**
- Modify: `frontend/src/app/reader/article/content/article-source.service.ts` (`errorDetail`, new `failureReason`)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (new `fallbackMessageKey`)
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html:176-197`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.scss` (`.reader-fallback-quiet`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`reader.readerFallback` → `reader.fallback.*`)
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts` (`describe('reader fallback: retry and error detail')`)

**Interfaces:**
- Produces: `ArticleSource.failureReason: Signal<ReaderFailure['reason'] | null>`; `ArticleSource.errorDetail` returns `failure.detail` (no reason-code fallback).

- [ ] **Step 1: Write the failing tests** — replace `'falls back to the bare reason code when the server sent no cause'` and add:

```ts
    it.each([
      ['fetch', "Couldn't reach the page — showing the feed's summary."],
      ['unextractable', "Couldn't find an article on this page — showing the feed's summary."],
      ['mismatch', "The page didn't match this entry — showing the feed's summary."],
    ] as const)('says why the %s failure fell back to the feed', (reason, message) => {
      loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason })));
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.reader-fallback .reader-note')!.textContent).toContain(message);
    });

    it('keeps the generic note when the load fails at the transport', () => {
      loadMock.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 502 })));
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.reader-fallback .reader-note')!.textContent).toContain(
        "Couldn't load the full article — showing the feed's summary.",
      );
    });

    it.each(['empty', 'unextractable', 'mismatch'] as const)(
      'offers no Retry and no error disclosure when the page holds no article (%s)',
      (reason) => {
        loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason, detail: null })));
        const element = mount(entry()).nativeElement as HTMLElement;

        expect(element.querySelector('.note-link')).toBeNull();
        expect(element.querySelector('.reader-error')).toBeNull();
      },
    );

    it('offers no error disclosure for a fetch failure the server gave no cause for', () => {
      loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason: 'fetch', detail: null })));
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.note-link')).not.toBeNull();
      expect(element.querySelector('.reader-error')).toBeNull();
    });

    it('notes a page no longer than the feed quietly, outside the warning box', () => {
      loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason: 'empty' })));
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('app-warning-box')).toBeNull();
      expect(element.querySelector('.reader-fallback-quiet')!.textContent).toContain(
        "The page has no more text than the feed — showing the feed's version.",
      );
    });
```

Adjust the existing `'keeps the note on an entry without audio whose page holds no article'` (reason `empty`) to assert `.reader-fallback-quiet` instead of `.reader-fallback`, and the audio-first `it.each` to assert both `.reader-fallback` and `.reader-fallback-quiet` are null.

- [ ] **Step 2: Run to verify they fail**

Run: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/reader/article/reader-view/reader-view.component.spec.ts`
Expected: the new tests FAIL (generic text, Retry and `.reader-error` present, no `.reader-fallback-quiet`).

- [ ] **Step 3: Implement**

`article-source.service.ts` — replace `errorDetail` and add `failureReason`:

```ts
  /** The backend's reason the reader fell back; null after a transport failure,
   *  which carries no payload. */
  readonly failureReason = computed<ReaderFailure['reason'] | null>(() => {
    const state = this.state();
    return state.status === 'failed' ? (state.failure?.reason ?? null) : null;
  });
  /** The diagnostic detail behind the fallback note's "show error" disclosure: the
   *  server's own cause (a fetch's HTTP status or transport message), or the
   *  complete HTTP message of a transport failure in the browser. */
  readonly errorDetail = computed<string | null>(() => {
    const state = this.state();
    if (state.status !== 'failed') return null;
    return state.failure ? state.failure.detail : describeLoadError(state.error);
  });
```

`reader-view.component.ts` — next to `fallbackNoticeShown`:

```ts
  protected readonly fallbackMessageKey = computed(
    () => `reader.fallback.${this.source.failureReason() ?? 'transport'}`,
  );
```

`reader-view.component.html` — replace the `@if (fallbackNoticeShown())` block:

```html
            @if (fallbackNoticeShown()) {
              @if (source.failureReason() === 'empty') {
                <p class="reader-fallback-quiet">{{ fallbackMessageKey() | transloco }}</p>
              } @else {
                <app-warning-box class="reader-fallback">
                  <span class="warning-glyph" aria-hidden="true">⚠</span>
                  <div class="warning-text">
                    <p class="reader-note">
                      {{ fallbackMessageKey() | transloco }}
                      @if (!source.pageHoldsNoArticle()) {
                        <button
                          type="button"
                          class="note-link reads-as-text"
                          (click)="refreshArticle()"
                        >
                          {{ 'reader.retry' | transloco }}
                        </button>
                      }
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

`reader-view.component.scss` — after `.reader-note`:

```scss
.reader-fallback-quiet {
  margin: 0 0 var(--space-3);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}
```

`en.json` — replace `"readerFallback"` with:

```json
    "fallback": {
      "transport": "Couldn't load the full article — showing the feed's summary.",
      "fetch": "Couldn't reach the page — showing the feed's summary.",
      "no_url": "This entry links to no page — showing the feed's summary.",
      "unextractable": "Couldn't find an article on this page — showing the feed's summary.",
      "empty": "The page has no more text than the feed — showing the feed's version.",
      "mismatch": "The page didn't match this entry — showing the feed's summary."
    },
```

`de.json` — replace `"readerFallback"` with:

```json
    "fallback": {
      "transport": "Der vollständige Artikel konnte nicht geladen werden – es wird die Zusammenfassung des Feeds angezeigt.",
      "fetch": "Die Seite war nicht erreichbar – es wird die Zusammenfassung des Feeds angezeigt.",
      "no_url": "Dieser Eintrag verlinkt keine Seite – es wird die Zusammenfassung des Feeds angezeigt.",
      "unextractable": "Auf dieser Seite wurde kein Artikel gefunden – es wird die Zusammenfassung des Feeds angezeigt.",
      "empty": "Die Seite enthält nicht mehr Text als der Feed – es wird die Fassung des Feeds angezeigt.",
      "mismatch": "Die Seite passte nicht zu diesem Eintrag – es wird die Zusammenfassung des Feeds angezeigt."
    },
```

- [ ] **Step 4: Run the spec, then the gate**

Run: the Step 2 command → PASS; then `npm run check` and `npm run build` from `frontend/` (with the worktree jest flags if the check's jest leg trips on the `+`-free path it should not).
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add frontend docs/superpowers/plans/2026-10-08-1444-reader-fallback-reason.md
git commit -m "feat(#1444): the reader fallback note says why per reason and drops retry and the raw code where they cannot help"
```

## Verification left for the main checkout

A worktree cannot run the Docker stack. After the branch reaches the main checkout, open `/?subscription=1352&entry=566255-beeper-mcp` on :4200 and confirm the quiet line with no box, Retry or disclosure.
