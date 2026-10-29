# Unplayable-video card and cinema shortcut on components (#1892) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the last DOM-built controls from the article body: the hand-built unplayable-video card becomes a mounted `app-link-card` island, and the `t` cinema shortcut goes through the mounted player bars instead of querying and clicking buttons.

**Architecture:** Both pieces use `ArticleIslands` (`frontend/src/app/reader/article/reader-view/article-islands.ts`, #1886). **Control-island rule:** a chapters-only re-render runs over the SAME DOM, and `islands.releaseAll()` removes every island host. So an island never adopts or replaces a body node; it drives body nodes and re-seeds from their state. Here that means a failed video is *kept and hidden* (class `reader-video--unplayable`), and every render re-mounts a card before each marked video.

**Tech Stack:** Angular 20.3 (standalone, OnPush, signals, `createComponent` with `inputBinding`), Transloco 8, Jest (run in the Docker frontend container), Playwright.

**Spec:** GitHub issue #1892 (`gh issue view 1892 --repo larspohlmann/simple-feed-reader`).

## Global Constraints

- Behaviour for the reader is unchanged: the same card text, href, poster and host line; the same `t` key rules (no modifiers, not inside a text field, the bar in view else the first, nothing when no toggle is offered, `preventDefault` only when acting).
- No `querySelector` for buttons, no `.click()`, no `createElement` for controls left in body code.
- Comments: default to none; one line when one is needed (CLAUDE.md). Delete stale comments you touch.
- Component styles stay in sibling `.scss`; no hex colours or ad-hoc px outside the theme.
- `frontend/src/app/reader/article/article-content/article-content.component.scss` has a 14 kB `anyComponentStyle` error budget; add one short rule only.
- Commit format: `type(#1892): summary`. No Claude attribution. Commit only on branch `feature/1892-unplayable-card-cinema-shortcut`.
- Frontend tests: `docker compose exec -T frontend npx jest <paths>` from the repo root. Never run two Jest processes at once (container OOM). Gate: `docker compose exec -T frontend npm run check`, plus `docker compose exec -T frontend npx ng build` (template errors).

---

### Task 1: Unplayable-video card as an island

**Files:**
- Create: `frontend/src/app/reader/article/reader-view/article-unplayable-videos.ts`
- Create: `frontend/src/app/reader/article/reader-view/article-unplayable-videos.spec.ts` (from the old service spec)
- Delete: `frontend/src/app/reader/media/unplayable-video.service.ts`, `frontend/src/app/reader/media/unplayable-video.service.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.ts`
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.spec.ts` (the existing unplayable-video test, which expects `figure.link-card`)
- Modify: `frontend/src/app/reader/article/article-content/article-content.component.scss` (hide rule)
- Modify: `frontend/src/app/reader/link-card/_link-card-layout.scss:3-4` (comment names the deleted service)
- Modify: `frontend/e2e/body-islands.spec.ts`

**Interfaces:**
- Consumes: `ArticleIslands.mountBefore(anchor, component, bindings): ComponentRef<C>`; `LinkCardComponent` (`frontend/src/app/reader/link-card/link-card.component.ts`, inputs `card: LinkCardDto` required, `layout: 'article' | 'block'` required, `opensInReader` default null); `LinkCardDto` (`link-card/link-card.ts`); `streamsThroughHls(video)` from `frontend/src/app/reader/media/hls-streams.ts`.
- Produces: `class ArticleUnplayableVideos { constructor(islands: ArticleIslands, i18n: TranslocoService); mount(host: HTMLElement, pageUrl: string | null): void; fail(target: EventTarget | null, pageUrl: string | null): void }`.

- [ ] **Step 1: Write the spec** `article-unplayable-videos.spec.ts`. Port every case of the old `unplayable-video.service.spec.ts`, swapping `videos.replace(x, page)` for `videos.fail(x, page)` and `figure.link-card > a` for `app-link-card a`. The cards are now Angular components, so build the subject like `article-player-bars.spec.ts` does: `TestBed.configureTestingModule({ imports: [provideTranslocoTesting()], providers: [provideAccountIdentity()] })`, then `new ArticleUnplayableVideos(new ArticleIslands(TestBed.inject(Injector)), TestBed.inject(TranslocoService))`. Change the assertions as follows:
  - "replaces" → the video **stays** with class `reader-video--unplayable`, and the card host is `video.previousElementSibling` with tag `APP-LINK-CARD` and class `reader-island`. Same href, target, rel, poster `img` src, strong/span/small text as before. The poster img goes through `appProxiedImage`, so assert on the `src` attribute only.
  - "once its last source fails, and not before" → after the first source's error there is no card, and the video has no class.
  - the hls and non-video cases → no card, no class.
  - New: a second `fail` on the same video mounts no second card (`querySelectorAll('app-link-card')` length 1).
  - New, the re-render case: after `fail`, call `islands.releaseAll()`. Expect no card and the video still marked. Then `videos.mount(host, PAGE)` brings exactly one card back, before the video.
  - New: `mount` on a body with no marked video mounts nothing.
- [ ] **Step 2: Run it, expect FAIL** (module not found): `docker compose exec -T frontend npx jest src/app/reader/article/reader-view/article-unplayable-videos.spec.ts`
- [ ] **Step 3: Implement** `article-unplayable-videos.ts`:

```ts
import { inputBinding } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { LinkCardDto } from '../../link-card/link-card';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { streamsThroughHls } from '../../media/hls-streams';
import { ArticleIslands } from './article-islands';

const UNPLAYABLE = 'reader-video--unplayable';

/** A body video the browser cannot decode, hidden behind a card to where it can be watched. */
export class ArticleUnplayableVideos {
  constructor(
    private readonly islands: ArticleIslands,
    private readonly i18n: TranslocoService,
  ) {}

  /** A chapters-only re-render keeps the body, so the videos found unplayable keep their card. */
  mount(host: HTMLElement, pageUrl: string | null): void {
    for (const video of Array.from(host.querySelectorAll<HTMLVideoElement>(`video.${UNPLAYABLE}`))) {
      this.mountCard(video, pageUrl);
    }
  }

  fail(target: EventTarget | null, pageUrl: string | null): void {
    const video = failedVideo(target);
    if (!video || streamsThroughHls(video) || video.classList.contains(UNPLAYABLE)) return;
    video.classList.add(UNPLAYABLE);
    this.mountCard(video, pageUrl);
  }

  private mountCard(video: HTMLVideoElement, pageUrl: string | null): void {
    const card = this.cardOf(video, pageUrl);
    this.islands.mountBefore(video, LinkCardComponent, [
      inputBinding('card', () => card),
      inputBinding('layout', () => 'article'),
    ]);
  }

  private cardOf(video: HTMLVideoElement, pageUrl: string | null): LinkCardDto {
    const url =
      pageUrl ??
      video.getAttribute('src') ??
      video.querySelector('source')?.getAttribute('src') ??
      '';
    return {
      url,
      title: this.i18n.translate('reader.unplayableVideo.title'),
      description: this.i18n.translate('reader.unplayableVideo.action'),
      imageUrl: video.getAttribute('poster'),
      siteName: hostnameOf(url),
      readerToken: '',
    };
  }
}

function failedVideo(target: EventTarget | null): HTMLVideoElement | null {
  if (target instanceof HTMLVideoElement) return target;
  if (!(target instanceof HTMLSourceElement)) return null;
  // Only the last <source> failing leaves the browser nothing to play.
  if (target.nextElementSibling instanceof HTMLSourceElement) return null;
  return target.closest('video');
}

function hostnameOf(url: string): string {
  try {
    return new URL(url, document.baseURI).hostname;
  } catch {
    return '';
  }
}
```

  The card object is built once per mount. `inputBinding` re-reads its getter on every change detection, and a fresh object would reset `LinkCardComponent.imageFailed`. The `readerToken: ''` is safe because `opensInReader` stays null, so the token is never read.
- [ ] **Step 4: Wire it into `ArticleBody`** (`article-body.service.ts`):
  - Remove the `UnplayableVideoService` import and field.
  - Add `import { TranslocoService } from '@jsverse/transloco';` and `private readonly unplayableVideos = new ArticleUnplayableVideos(this.islands, inject(TranslocoService));`, declared after `islands`.
  - In `recoverFailedMedia`, call `this.unplayableVideos.fail(event.target, parts.pageUrl());`.
  - In `render`, inside `runOutsideAngular`, add `this.unplayableVideos.mount(host, parts.pageUrl());` directly after `this.playerBars.mount(host, listen);`. The bars read `box.firstElementChild` as the player, so they must mount before the card lands in front of the video.
- [ ] **Step 5: Hide the marked video.** In `article-content.component.scss`, after the `:host ::ng-deep .reader-cinema > :is(.reader-embed, video)` rule (around line 395), add:

```scss
:host ::ng-deep video.reader-video--unplayable {
  display: none;
}
```
- [ ] **Step 6: Fix the comment** in `_link-card-layout.scss` lines 3-4. It should no longer name `unplayable-video.service.ts`: "Old stored bodies carry this markup, so it must not change."
- [ ] **Step 7: Update `article-body.service.spec.ts`.** Its unplayable-video test (around line 215) asserts `content.querySelector('video')` is null and reads `figure.link-card a`. Change it so the video is kept with `reader-video--unplayable` and the card is read from `app-link-card a`. Keep the href and the title assertion. Add one test: fail the video, then re-render chapters only by setting `chapters` to a non-equal list, the way the existing chapters-only re-render tests in this spec do. Then `await Promise.resolve()` and expect exactly one `app-link-card`.
- [ ] **Step 8: e2e.** In `frontend/e2e/body-islands.spec.ts`:
  - Append `'<video controls src="https://media.example.invalid/missing.mp4"></video>'` to `BODY`.
  - In `openArticle`, before the reload, add `await page.route('https://media.example.invalid/**', (route) => route.fulfill({ status: 404, body: '' }));`.
  - Add a test: `const card = pane.locator('app-link-card');`, then `await expect(card).toContainText("This video can't play in your browser");`, `await expect(card.locator('a')).toHaveAttribute('href', 'https://example.invalid/1');` and `await expect(pane.locator('video.reader-video--unplayable')).toBeHidden();`.
  - Check that the slideshow and narration tests still pass with the added video.
- [ ] **Step 9: Run** the two specs (one Jest process): `docker compose exec -T frontend npx jest src/app/reader/article/reader-view/article-unplayable-videos.spec.ts src/app/reader/article/reader-view/article-body.service.spec.ts`. Expect PASS.
  - **Break-check:** temporarily make `mount()` a no-op. The re-render tests must FAIL; then restore it. Do the restore by editing, not with `git checkout --`.
  - Then run `docker compose exec -T frontend npx playwright test e2e/body-islands.spec.ts`. The Docker stack must be up from this checkout.
- [ ] **Step 10: Commit** `feat(#1892): mount the unplayable-video card as a link-card island`

### Task 2: The `t` cinema shortcut through the player bars

**Files:**
- Modify: `frontend/src/app/reader/article/player-bar/player-bar.component.ts`, `.html`
- Modify: `frontend/src/app/reader/article/reader-view/article-player-bars.ts`, `article-player-bars.spec.ts`
- Modify: `frontend/src/app/reader/article/decorators/reader-cinema.ts`, `reader-cinema.spec.ts` (remove `toggleCinemaByKey` and its cases)
- Modify: `frontend/src/app/reader/article/reader-view/article-body.service.ts`, `article-body.service.spec.ts`
- Check: `frontend/src/app/reader/article/player-bar/player-bar.component.spec.ts`, if it exists and calls `toggleCinema(box)`

**Interfaces:**
- Consumes: `isTextEntryTarget` from `frontend/src/app/shared/text-entry-target`.
- Produces:
  - `PlayerBarComponent`: public `toggleCinema(): void` (no argument; does nothing without a cinema box), `offersCinema(): boolean` and `isInView(): boolean`;
  - `ArticlePlayerBars`: `toggleCinemaByKey(event: KeyboardEvent): void` and `forget(): void`.

- [ ] **Step 1: Move the specs.** Port the `describe('toggleCinemaByKey')` cases from `reader-cinema.spec.ts` into `article-player-bars.spec.ts`. Mount the real bars with `mountBars(CINEMA_BOX + CINEMA_BOX, undefined)` and keep the `ArticlePlayerBars` instance (change `mountBars` to return `{ host, bars }`). Read "widened" from each `.reader-cinema` box's `reader-cinema--on` class. These cases come over:
  - the first bar acts when none is in view, and `defaultPrevented` is true;
  - the in-view bar acts: stub the second bar host's (`app-player-bar`) `getBoundingClientRect` to `{ top: 100, bottom: 400 }`;
  - modifiers, `T` and `x` are ignored;
  - input and select targets are ignored;
  - nothing happens where cinema is not offered: set each `.reader-cinema__toggle`'s `style.display = 'none'`, and `defaultPrevented` stays false;
  - nothing happens with no bars mounted.

  Add `forget()`: after it, `t` does nothing. Add a non-cinema bar (SoundCloud box with listen actions) next to a cinema box: `t` widens the cinema box. Mock `Element.prototype.scrollIntoView = jest.fn()` in `beforeAll` if the spec doesn't already.
- [ ] **Step 2: Run, expect FAIL:** `docker compose exec -T frontend npx jest src/app/reader/article/reader-view/article-player-bars.spec.ts`
- [ ] **Step 3: Implement `PlayerBarComponent`:**
  - Add `ElementRef` and `viewChild` imports.
  - Add `private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);` and `private readonly cinemaToggle = viewChild<ElementRef<HTMLButtonElement>>('cinemaToggle');`.
  - Replace `toggleCinema(box)` with:

```ts
  toggleCinema(): void {
    const box = this.cinemaBox();
    if (!box) return;
    this.widened.set(box.classList.toggle(WIDENED));
    box.scrollIntoView({ block: 'nearest' });
  }

  /** The toggle shows only where the reading pane has room to widen. */
  offersCinema(): boolean {
    const toggle = this.cinemaToggle()?.nativeElement;
    return toggle !== undefined && getComputedStyle(toggle).display !== 'none';
  }

  isInView(): boolean {
    const { top, bottom } = this.host.nativeElement.getBoundingClientRect();
    return bottom > 0 && top < window.innerHeight;
  }
```
  In the template, change the cinema button's `@if (cinemaBox(); as box)` to `@if (cinemaBox())`, add `#cinemaToggle` to the button, and use `(click)="toggleCinema()"`.
- [ ] **Step 4: Implement `ArticlePlayerBars`:**
  - Add `private cinemaBars: PlayerBarComponent[] = [];`.
  - `mount()` starts with `this.cinemaBars = [];`.
  - `mountBar` returns `ComponentRef<PlayerBarComponent>` (the result of `islands.mountAfter`). The cinema-box loop pushes `ref.instance`.
  - Add:

```ts
  /** `t` widens or narrows the video in view, else the first one, like YouTube's theatre key. */
  toggleCinemaByKey(event: KeyboardEvent): void {
    if (!isCinemaKey(event)) return;
    const offered = this.cinemaBars.filter((bar) => bar.offersCinema());
    const bar = offered.find((candidate) => candidate.isInView()) ?? offered[0];
    if (!bar) return;
    event.preventDefault();
    bar.toggleCinema();
  }

  forget(): void {
    this.cinemaBars = [];
  }
```
  Move `isCinemaKey` (with its `isTextEntryTarget` import) from `reader-cinema.ts` as a module function. In `reader-cinema.ts`, delete `toggleCinemaByKey`, `isCinemaKey`, `isOffered` and `isInViewport`, and drop the unused import. Delete the moved cases from `reader-cinema.spec.ts` and its `toggleCinemaByKey` import. If `BOXED` is now unused there, delete it too.
- [ ] **Step 5: Wire it into `ArticleBody`:**
  - The keydown handler becomes `const onKeydown = (event: KeyboardEvent): void => this.playerBars.toggleCinemaByKey(event);`.
  - Remove the `toggleCinemaByKey` import.
  - Call `this.playerBars.forget();` next to both `this.islands.releaseAll();` calls (render cleanup and destroy).
  - If `content` is no longer read anywhere except `connect`, keep it only if still used; otherwise delete the field and its assignment.
  - Update the class doc comment if it still says "the `t` cinema key" incorrectly; it is still true that ArticleBody owns the key, so keep the phrase if accurate.
- [ ] **Step 6: Update `article-body.service.spec.ts`.** Replace `cinemaToggleClicks` (it spies on a button's `click`) with a read of `content.querySelector('.reader-cinema')!.classList.contains('reader-cinema--on')`:
  - "widens the video on the t key" → true after `pressT()`;
  - the destroy test → false after `injector.destroy(); pressT();`.
- [ ] **Step 7: Run** `docker compose exec -T frontend npx jest src/app/reader/article` (one process). Expect PASS.
  - **Break-check:** make `offersCinema` return `true` always. "Not offered" must FAIL; restore it by editing.
  - Then run the gates: `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build`. The build must stay within budgets.
- [ ] **Step 8: Commit** `refactor(#1892): route the t cinema key through the mounted player bars`
