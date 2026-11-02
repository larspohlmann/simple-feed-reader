# Link reader reading aids (#1984) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The link reader gains the corner share disc, table of contents, reading time and scroll restore, by deleting the `readingAids` holding gate #1983 put on the shared `app-article-page` shell.

**Architecture:** #1983 built every one of the four features into `ArticlePageComponent` and gated each on `source.readingAids` (true for the reader view, false for the link reader). This PR deletes that flag from the contract, both sources and the test stub, and makes the shell's four gated parts unconditional. The link source's page key (`link:<url>`) already exists, so scroll restore needs no new wiring.

**Tech Stack:** Angular 20 (standalone components, signals), Jest in Docker, Playwright for the live check.

**Spec:** `docs/superpowers/specs/2026-11-02-article-page-shell-design.md`, section "PR 3 — #1984".

## Global Constraints

- No visible or behavioural change in the reader view: every `reader-view.component.spec.ts` assertion stays as it is.
- The link reader keeps every existing `link-reader.component.spec.ts` assertion; the four features are its intended changes.
- Corner share disc with the link's original url; the link reader projects no flags.
- Table of contents from the decorated link body; shown only when the page has headings (the TOC's own rule: at least 3).
- Reading time in the shell's meta line, after the byline (the site name), estimated from the body html.
- Scroll restore keyed `link:<url>`; two cards linking the same page share one position.
- Done-when: no file in `article-page/`, `article-content/`, `article-layer/`, `reading/`, `share/`, `selection-share/`, `link-reader/` imports `EntryDto` (already true at the base; keep it true).
- Branch `feature/1984-link-reader-reading-aids`. Commits `feat(#1984): …`, `test(#1984): …`; no attribution lines.
- Tests run in Docker from the repo root: `docker compose exec -T frontend npx jest <paths>; echo EXIT=$?` (workdir `/app` = `frontend/`). Never two Jest runs at once. After template changes also `docker compose exec -T frontend npx ng build`.
- Comments default to none, one line when needed; no narration. Template cyclomatic complexity limit 25.

---

## File map

| File | Change |
|---|---|
| `frontend/src/app/reader/article/article-page/article-page-source.ts` | delete `readingAids` and its docblock |
| `frontend/src/app/reader/article/article-page/article-page.component.ts` | `restoreKey`, `readingMinutes`, `cornerShareUrl` lose the gate |
| `frontend/src/app/reader/article/article-page/article-page.component.html` | the TOC loses its `@if (source.readingAids)` |
| `frontend/src/app/reader/article/article-page/article-page.component.spec.ts` | the "without reading aids" test goes; the stub override `readingAids: true` goes |
| `frontend/src/app/reader/article/reader-view/entry-article-page.ts` | delete `readonly readingAids = true;` |
| `frontend/src/app/reader/article/link-reader/link-article-source.service.ts` | delete `readonly readingAids = false;` |
| `frontend/src/testing/article-page-source-testing.ts` | delete the `readingAids` override, field and assignment |
| `frontend/src/app/reader/article/link-reader/link-reader.component.spec.ts` | four new tests, one per feature |

---

### Task 1: Every article page gets the reading aids

**Files:**
- Modify: the eight files in the file map.
- Test: `frontend/src/app/reader/article/link-reader/link-reader.component.spec.ts`, `frontend/src/app/reader/article/article-page/article-page.component.spec.ts`

**Interfaces:**
- Consumes: `ArticlePageSource` (`article-page-source.ts`), `linkPageKey(url: string): string` from `../reading/article-page-key`, `articleScrollKey(pageKey: string): string` from `../../scroll/list-scroll-memory`.
- Produces: `ArticlePageSource` without `readingAids`. Nothing later depends on it.

- [ ] **Step 1: Write the four failing link-reader tests**

Add to `link-reader.component.spec.ts`, inside `describe('LinkReaderComponent', …)` after the `'carries none of the entry reader chrome'` test. Add the imports `articleScrollKey` (from `'../../scroll/list-scroll-memory'`) and `linkPageKey` (from `'../reading/article-page-key'`).

```ts
  describe('reading aids', () => {
    const longPage = aReaderArticle({
      url: 'https://news.test/story',
      title: 'The extracted story',
      siteName: 'News',
      contentHtml: `<p>${Array.from({ length: 660 }, (_, index) => `w${index}`).join(' ')}</p>`,
    });

    /** Let the content-processing microtask and any follow-up effect settle. */
    async function settle(fixture: ComponentFixture<LinkReaderComponent>): Promise<void> {
      await Promise.resolve();
      fixture.detectChanges();
      await Promise.resolve();
    }

    it('shares the link from the corner disc too', () => {
      const fixture = mount(aLinkCard({ url: 'https://news.test/story' }));

      const corner = fixture.debugElement
        .queryAll(By.directive(ShareButtonComponent))
        .find((share) => share.nativeElement.closest('.corner-actions'));
      expect(corner).toBeDefined();
      expect((corner!.componentInstance as ShareButtonComponent).url()).toBe(
        'https://news.test/story',
      );
    });

    it('lists the page headings in a table of contents', async () => {
      const fixture = mount(null);
      reply(
        fixture,
        aReaderArticle({
          url: 'https://news.test/story',
          contentHtml: '<h2>Alpha</h2><p>a</p><h2>Beta</h2><p>b</p><h3>Gamma</h3>',
        }),
      );
      await settle(fixture);

      const element = elementOf(fixture);
      const toggle = element.querySelector('.reader-panel__toggle') as HTMLButtonElement;
      expect(toggle).not.toBeNull();
      toggle.click();
      fixture.detectChanges();
      const items = [...element.querySelectorAll('.toc-list button')].map((button) =>
        button.textContent?.trim(),
      );
      expect(items).toEqual(['Alpha', 'Beta', 'Gamma']);
    });

    it('estimates the reading time after the site name', () => {
      const fixture = mount(aLinkCard({ siteName: 'news.test' }));
      reply(fixture, longPage);

      const meta = elementOf(fixture).querySelector('.meta')!.textContent!;
      expect(meta).toContain('≈ 3 min');
      expect(meta.indexOf('news.test')).toBeLessThan(meta.indexOf('≈ 3 min'));
    });

    it('reopens the page where it was left', async () => {
      sessionStorage.setItem(articleScrollKey(linkPageKey(FIRST_LINK.url)), '900');
      const fixture = mount();
      const scroller = elementOf(fixture).querySelector('.scroller') as HTMLElement;
      const scroll = { top: 0 };
      Object.defineProperty(scroller, 'scrollTop', {
        configurable: true,
        get: () => scroll.top,
        set: (value: number) => {
          scroll.top = value;
        },
      });

      reply(fixture, longPage);
      await settle(fixture);

      expect(scroll.top).toBe(900);
      sessionStorage.clear();
      fixture.destroy();
    });
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/link-reader/link-reader.component.spec.ts; echo EXIT=$?`
Expected: four failures under "reading aids":
- `corner` is undefined.
- The toggle is null.
- The meta line lacks `≈ 3 min`.
- `scroll.top` is 0, not 900.

Quote the four failure lines in your report. If the restore test passes before the change, the probe is wrong: stop and report.

- [ ] **Step 3: Delete the gate from the contract, sources and stub**

In `article-page-source.ts`, delete these two lines from `ArticlePageSource`:

```ts
  /** The corner share disc, TOC, reading time and scroll restore; #1984 gives them to every page. */
  readonly readingAids: boolean;
```

In `reader-view/entry-article-page.ts`, delete `  readonly readingAids = true;`.
In `link-reader/link-article-source.service.ts`, delete `  readonly readingAids = false;`.
In `frontend/src/testing/article-page-source-testing.ts`, delete the `readingAids?: boolean;` override member, the `readingAids = false;` field and the `this.readingAids = overrides.readingAids ?? false;` assignment.

- [ ] **Step 4: Make the shell's four parts unconditional**

In `article-page.component.ts`, replace:

```ts
  private readonly restoreKey = computed(() =>
    this.source.readingAids ? this.source.key() : undefined,
  );
```

with:

```ts
  private readonly restoreKey = this.source.key;
```

Then replace:

```ts
  protected readonly readingMinutes = computed(() =>
    this.source.readingAids ? estimateReadingMinutes(this.source.body()?.html ?? '') : null,
  );

  protected readonly cornerShareUrl = computed(() =>
    this.source.readingAids ? this.source.url() : null,
  );
```

with:

```ts
  protected readonly readingMinutes = computed(() =>
    estimateReadingMinutes(this.source.body()?.html ?? ''),
  );
```

`restoreKey` is now an alias of `source.key`. Inline it: replace every `this.restoreKey()` with `this.source.key()` and `this.restore.reseat(this.restoreKey)` with `this.restore.reseat(this.source.key)`, then delete the `restoreKey` line.

In `article-page.component.html`:
- Replace `@if (cornerShareUrl(); as url) {` with `@if (source.url(); as url) {` (the corner disc).
- Replace

```html
    @if (source.readingAids) {
      <app-reader-toc [entries]="toc()" [(open)]="tocOpen" (jump)="scrollToHeading($event)" />
    }
```

with

```html
    <app-reader-toc [entries]="toc()" [(open)]="tocOpen" (jump)="scrollToHeading($event)" />
```

- [ ] **Step 5: Update the shell spec**

In `article-page.component.spec.ts`:
- Delete the test `'leaves out the corner disc, TOC and reading time without reading aids'` and its body.
- Delete `readingAids: true,` from the `StubPageSource` overrides.

Run `grep -rn "readingAids" frontend/src` and expect no output.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader; echo EXIT=$?`
Expected: PASS. All four new link-reader tests pass and every reader-view test is unchanged and passing. A reader-view relative-date flake ("102 days ago" vs "103") can fail once; rerun that spec alone before treating it as real.

Then:
- `docker compose exec -T frontend npx ng build` exits 0.
- `docker compose exec -T frontend npx eslint src/app/reader/article src/testing` is clean.
- `docker compose exec -T frontend npx prettier --check` on the touched files passes.
- `docker compose exec -T frontend npx tsc -p tsconfig.spec.json --noEmit` is clean.

Run the done-when check: `grep -rln "EntryDto" frontend/src/app/reader/article/{article-page,article-content,article-layer,reading,share,selection-share,link-reader} | grep -v spec` gives no output.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/article frontend/src/testing
git commit -m "feat(#1984): the link reader gets the corner share disc, contents, reading time and scroll restore"
```

---

### Task 2 (controller): live check, gate, PR

- [ ] On :4200 with a stubbed API, at 1280×900 and 375×812, run a throwaway Playwright spec built on `e2e/link-card-reader.spec.ts`'s stubs, with a long linked page that has three headings. Check:
  - the corner disc is present and shares the link url;
  - the TOC lists the headings and jumps to one;
  - `≈ N min` follows the site name;
  - after scrolling, a `page.reload()` keeps `?link=` and the page reopens near the same offset;
  - the back button returns to the article and to the list.

  Delete the spec afterwards.
- [ ] Reader-view parity: computed-style dump against `develop` at both sizes. Expect no change in the reader view.
- [ ] `npm run check`, `ng build`, the Playwright article smokes (`link-card-reader body-islands article-back-desktop article-mini-header article-reading-progress article-slide-out article-tail-space reader-smoke reading-focus-blocks listen-to-embed chapters`).
- [ ] `/simplify`, then a PR into `develop` with `Closes #1984` and `Closes #1978` (#1978's "Done when" checked against the code).
