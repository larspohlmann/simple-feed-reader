# Article page shell (#1983) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Both article views render through one `app-article-page` shell fed by an `ArticlePageSource`, with no visible change in the reader view and only the failure box's inline Retry added to the link reader.

**Architecture:** A source contract (`ArticlePageSource`, token `ARTICLE_PAGE_SOURCE`) plus `provideArticlePage({ source, autoplayRegion })`, which each view puts in its own `providers` (projected content resolves its injector from the declaring view, so the providers must sit on the outer view). The shell injects the source and owns the layer bindings, progress rail, reading scope, selection, TOC, scroll restore and the generic skeleton; views fill named `ng-content` slots and three `<ng-template articlePageSlot>` slots (for parts behind the loading gate). The reader view adapts entry + `ArticleSource` through `EntryArticlePage`; `LinkArticleSource` implements the contract directly. A `readingAids` flag on the source keeps the corner share disc, TOC, reading time and scroll restore reader-only until #1984 deletes it.

**Tech Stack:** Angular 20 (standalone, signals, `contentChildren`, `NgTemplateOutlet`), Jest (jsdom) in the Docker `frontend` container, Sass, Playwright for live checks.

**Spec:** `docs/superpowers/specs/2026-11-02-article-page-shell-design.md` (section "PR 2 — #1983").

## Global Constraints

- No visible or behavioural change in the reader view: every existing `reader-view.component.spec.ts` assertion stays as it is; only test plumbing (which instance a method is called on) may change.
- The link reader keeps every existing `link-reader.component.spec.ts` assertion; its one intended change is a Retry button inside its failure box that calls `source.reload()`.
- Branch `refactor/1983-article-page-shell`, stacked on `refactor/1982-article-page-groundwork` (PR #1985). Commit format `refactor(#1983): …`, `test(#1983): …`; no attribution lines.
- Tests run in Docker from the repo root: `docker compose exec -T frontend npx jest <paths>; echo EXIT=$?` (workdir `/app` = `frontend/`). Never two Jest runs at once. After any template change also `docker compose exec -T frontend npx ng build`.
- Angular emulated encapsulation: a parent's `.meta a`-style rule does not reach nodes rendered by another component's template. Projected content and `<ng-template>` slots keep the *declaring* component's encapsulation. Move each style rule to the component whose template renders the node.
- Comments default to none, one line when needed; delete narrations. No hex colours or ad-hoc `px` in `.scss`. Template cyclomatic complexity limit is 25 (`@angular-eslint/template/cyclomatic-complexity`); a breach means extract, never squeeze.
- A known flake: a reader-view spec can fail once on a relative date ("102 days ago" vs "103"); rerun that spec alone before treating it as real.

## File map

| File | Responsibility |
|---|---|
| `src/app/reader/article/article-page/article-page-source.ts` | new: `ArticlePageSource` + its value shapes + `ARTICLE_PAGE_SOURCE` |
| `src/app/reader/article/article-page/article-page-slot.directive.ts` | new: `ng-template[articlePageSlot]` |
| `src/app/reader/article/article-page/provide-article-page.ts` (+ spec) | new: the providers both views list |
| `src/app/reader/article/article-page/article-page.component.{ts,html,scss}` (+ spec) | new: the shell |
| `src/app/reader/article/article-page/_article-page.scss`, `_article-toolbar.scss` | deleted — their rules move into the shell's own `.scss` |
| `src/app/reader/article/reading/article-page-key.ts` (+ spec) | gains `linkPageKey(url)` |
| `src/app/reader/article/reading/reading-scope.service.ts` (+ spec) | `trailing` becomes a `Signal` |
| `src/app/reader/article/article-layer/article-layer.component.ts` (+ spec host) | `resetKey` takes the page key |
| `src/app/reader/article/link-reader/link-article-source.service.ts` | implements `ArticlePageSource` |
| `src/app/reader/article/link-reader/link-reader.component.{ts,html,scss}` | the shell plus the back label |
| `src/app/reader/shell/reader-shell.component.html` | drops `[target]` on `app-link-reader` |
| `src/app/reader/article/reader-view/entry-article-page.ts` | new: adapter, entry + `ArticleSource` → `ArticlePageSource` |
| `src/app/reader/article/reader-view/reader-view.component.{ts,html,scss}` | the shell plus entry slots |

All paths are relative to `frontend/` unless they start with `docs/`.

---

### Task 1: The page contract, its slot directive and providers

**Files:**
- Create: `src/app/reader/article/article-page/article-page-source.ts`
- Create: `src/app/reader/article/article-page/article-page-slot.directive.ts`
- Create: `src/app/reader/article/article-page/provide-article-page.ts`
- Test: `src/app/reader/article/article-page/provide-article-page.spec.ts`
- Modify: `src/app/reader/article/reading/article-page-key.ts`, `article-page-key.spec.ts`
- Modify: `src/app/reader/article/reading/reading-scope.service.ts` (`ReadingScopeParts.trailing`, field `trailing`, lines ~35, 67, 92–106, 140, 173, 183)
- Test: `src/app/reader/article/reading/reading-scope.service.spec.ts` (line ~56)
- Modify: `src/app/reader/article/reader-view/reader-view.component.ts:253`, `src/app/reader/article/link-reader/link-reader.component.ts:90` (wrap their `trailing` in a signal)
- Modify: `src/app/reader/article/article-layer/article-layer.component.ts:52` (`resetKey` type), `article-layer.component.spec.ts` host

**Interfaces:**
- Produces, in `article-page-source.ts`:

```ts
export interface ArticlePageBody { readonly html: string; readonly pageUrl: string | null; }
export interface ArticlePageBodyAudio {
  readonly enclosureUrl: string | null;
  readonly chapters: ChapterDto[];
  readonly track: AudioTrack | null;
  readonly origin: EmbedTrackOrigin | null;
}
export interface ArticlePageFailure {
  readonly messageKey: string;
  readonly quiet: boolean;
  readonly detail: string | null;
  readonly offersOriginal: boolean;
}
export interface ArticlePageFavicon { readonly url: string | null; readonly avatarUrl: string | null; }
export interface ArticlePagePaywall { readonly url: string | null; }
export interface ArticlePageSource {
  readonly key: Signal<string | undefined>;
  readonly url: Signal<string | null>;
  readonly title: Signal<string>;
  readonly shareTitle: Signal<string>;
  readonly titleHidden: Signal<boolean>;
  readonly nameplate: Signal<string>;
  readonly favicon: Signal<ArticlePageFavicon | null>;
  readonly body: Signal<ArticlePageBody | null>;
  readonly bodyAudio: Signal<ArticlePageBodyAudio | null>;
  readonly lead: Signal<HeroImageDto | null>;
  readonly paywall: Signal<ArticlePagePaywall | null>;
  readonly loading: Signal<boolean>;
  readonly canRefresh: Signal<boolean>;
  readonly failure: Signal<ArticlePageFailure | null>;
  readonly socialPost: Signal<boolean>;
  readonly readingAids: boolean;
  dropLead(): void;
  reload(): void;
}
export const ARTICLE_PAGE_SOURCE: InjectionToken<ArticlePageSource>;
```

- `ArticlePageSlotDirective` (selector `ng-template[articlePageSlot]`), input `name` aliased `articlePageSlot`, type `ArticlePageSlotName = 'notices' | 'afterBody' | 'footer'`, field `template: TemplateRef<unknown>`.
- `provideArticlePage(page: ArticlePageProviders): Provider[]` with `ArticlePageProviders { source: Type<ArticlePageSource>; autoplayRegion: () => AutoplayRegion }`; `noAutoplayRegion(): AutoplayRegion`.
- `linkPageKey(url: string): string` → `'link:<url>'`.
- `ReadingScopeParts.trailing: Signal<readonly TrailingPart[]>`.
- `ArticleLayerComponent.resetKey = input.required<string | undefined>()`.

- [ ] **Step 1: Write the failing specs**

`src/app/reader/article/article-page/provide-article-page.spec.ts`:

```ts
import { Injectable, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AUTOPLAY_REGION } from '../../media/autoplay/autoplay-region';
import { ARTICLE_PAGE_SOURCE, ArticlePageSource } from './article-page-source';
import { noAutoplayRegion, provideArticlePage } from './provide-article-page';

@Injectable()
class FakePageSource implements ArticlePageSource {
  readonly key = signal<string | undefined>('link:https://news.test/a');
  readonly url = signal<string | null>(null);
  readonly title = signal('');
  readonly shareTitle = signal('');
  readonly titleHidden = signal(false);
  readonly nameplate = signal('');
  readonly favicon = signal(null);
  readonly body = signal(null);
  readonly bodyAudio = signal(null);
  readonly lead = signal(null);
  readonly paywall = signal(null);
  readonly loading = signal(false);
  readonly canRefresh = signal(false);
  readonly failure = signal(null);
  readonly socialPost = signal(false);
  readonly readingAids = false;
  dropLead(): void {}
  reload(): void {}
}

describe('provideArticlePage', () => {
  it('hands the page the view’s own source instance', () => {
    TestBed.configureTestingModule({
      providers: [
        FakePageSource,
        provideArticlePage({ source: FakePageSource, autoplayRegion: noAutoplayRegion }),
      ],
    });

    expect(TestBed.inject(ARTICLE_PAGE_SOURCE)).toBe(TestBed.inject(FakePageSource));
  });

  it('builds the autoplay region from the view’s factory', () => {
    const region = () => undefined;
    TestBed.configureTestingModule({
      providers: [FakePageSource, provideArticlePage({ source: FakePageSource, autoplayRegion: () => region })],
    });

    expect(TestBed.inject(AUTOPLAY_REGION)).toBe(region);
  });
});
```

In `article-page-key.spec.ts` add:

```ts
describe('linkPageKey', () => {
  it('keys a link page by its url', () => {
    expect(linkPageKey('https://news.test/story')).toBe('link:https://news.test/story');
  });
});
```

and import `linkPageKey` beside `entryPageKey`.

In `reading-scope.service.spec.ts` line ~56, the `trailing: [ … ]` array passed to `connect` becomes `trailing: signal([ … ])` (import `signal` from `@angular/core` if not already).

- [ ] **Step 2: Run them to see them fail**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/article-page src/app/reader/article/reading; echo EXIT=$?
```

Expected: FAIL — `provide-article-page` and `linkPageKey` missing; reading-scope spec type error on `trailing`.

- [ ] **Step 3: Write the contract and directive**

`src/app/reader/article/article-page/article-page-source.ts`:

```ts
import { InjectionToken, Signal } from '@angular/core';
import { AudioTrack } from '../../audio-player.service';
import { EmbedTrackOrigin } from '../../audio/embed-track';
import { ChapterDto, HeroImageDto } from '../../models';

export interface ArticlePageBody {
  readonly html: string;
  readonly pageUrl: string | null;
}

export interface ArticlePageBodyAudio {
  readonly enclosureUrl: string | null;
  readonly chapters: ChapterDto[];
  readonly track: AudioTrack | null;
  readonly origin: EmbedTrackOrigin | null;
}

export interface ArticlePageFailure {
  readonly messageKey: string;
  readonly quiet: boolean;
  readonly detail: string | null;
  readonly offersOriginal: boolean;
}

export interface ArticlePageFavicon {
  readonly url: string | null;
  readonly avatarUrl: string | null;
}

export interface ArticlePagePaywall {
  readonly url: string | null;
}

/** What an article page shows, whichever view fills it. */
export interface ArticlePageSource {
  readonly key: Signal<string | undefined>;
  readonly url: Signal<string | null>;
  readonly title: Signal<string>;
  readonly shareTitle: Signal<string>;
  readonly titleHidden: Signal<boolean>;
  readonly nameplate: Signal<string>;
  readonly favicon: Signal<ArticlePageFavicon | null>;
  readonly body: Signal<ArticlePageBody | null>;
  readonly bodyAudio: Signal<ArticlePageBodyAudio | null>;
  readonly lead: Signal<HeroImageDto | null>;
  readonly paywall: Signal<ArticlePagePaywall | null>;
  readonly loading: Signal<boolean>;
  readonly canRefresh: Signal<boolean>;
  readonly failure: Signal<ArticlePageFailure | null>;
  readonly socialPost: Signal<boolean>;
  /** The corner share disc, TOC, reading time and scroll restore; #1984 gives them to every page. */
  readonly readingAids: boolean;
  dropLead(): void;
  reload(): void;
}

export const ARTICLE_PAGE_SOURCE = new InjectionToken<ArticlePageSource>('ArticlePageSource');
```

`src/app/reader/article/article-page/article-page-slot.directive.ts`:

```ts
import { Directive, TemplateRef, inject, input } from '@angular/core';

export type ArticlePageSlotName = 'notices' | 'afterBody' | 'footer';

/** A part of the page a view fills, rendered by the page where that part belongs. */
@Directive({ selector: 'ng-template[articlePageSlot]' })
export class ArticlePageSlotDirective {
  readonly name = input.required<ArticlePageSlotName>({ alias: 'articlePageSlot' });
  readonly template = inject<TemplateRef<unknown>>(TemplateRef);
}
```

`src/app/reader/article/article-page/provide-article-page.ts`:

```ts
import { Provider, Type } from '@angular/core';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';
import { AUTOPLAY_REGION, AutoplayRegion } from '../../media/autoplay/autoplay-region';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ReadingScope } from '../reading/reading-scope.service';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { ARTICLE_PAGE_SOURCE, ArticlePageSource } from './article-page-source';

export interface ArticlePageProviders {
  readonly source: Type<ArticlePageSource>;
  readonly autoplayRegion: () => AutoplayRegion;
}

/** The services an article page reads, provided by the view so its projected parts reach them too. */
export function provideArticlePage(page: ArticlePageProviders): Provider[] {
  return [
    { provide: ARTICLE_PAGE_SOURCE, useExisting: page.source },
    ReadingScope,
    ArticleSelection,
    ArticleScrollRestore,
    ListAutoplayCoordinator,
    UnlockedVideoElements,
    { provide: AUTOPLAY_REGION, useFactory: page.autoplayRegion },
  ];
}

export function noAutoplayRegion(): AutoplayRegion {
  return () => undefined;
}
```

In `src/app/reader/article/reading/article-page-key.ts` add:

```ts
export function linkPageKey(url: string): string {
  return `link:${url}`;
}
```

- [ ] **Step 4: Make the reading scope's trailing parts a signal**

`src/app/reader/article/reading/reading-scope.service.ts`:
- `ReadingScopeParts.trailing` becomes `readonly trailing: Signal<readonly TrailingPart[]>;`
- the field becomes `private trailing: Signal<readonly TrailingPart[]> = signal([]);`
- in `connect`, delete `const focusParts = …`; inside the applier effect's `blocks` callback use `this.presentElements(this.trailing().filter((part) => part.joinsFocus))`.
- every other `this.trailing` read becomes `this.trailing()` (lines ~140, ~173, ~183).

The views' `connect` calls: `reader-view.component.ts:253` `trailing: this.trailingParts` → `trailing: signal(this.trailingParts)`; `link-reader.component.ts:90` `trailing: []` → `trailing: signal([])` (import `signal`). Both lines disappear in Tasks 3–4; this keeps the tree green now.

`src/app/reader/article/article-layer/article-layer.component.ts`: `readonly resetKey = input.required<ReaderTarget>();` → `readonly resetKey = input.required<string | undefined>();`; drop the `ReaderTarget` import if unused. In the views' templates for now: reader-view `[resetKey]="e.id"` → `[resetKey]="'entry:' + e.id"`; link-reader `[resetKey]="target()"` → `[resetKey]="target().url"`. Both lines disappear in Tasks 3–4. In `article-layer.component.spec.ts` make the host's `page` signal a `string` (e.g. `signal('entry:1')`, and any later `.set(…)` a different string).

- [ ] **Step 5: Run the specs and build**

```bash
docker compose exec -T frontend npx jest src/app/reader/article; echo EXIT=$?
docker compose exec -T frontend npx ng build; echo EXIT=$?
```

Expected: all PASS; build EXIT=0.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1983): the article page's source contract, slot directive and providers"
```

---

### Task 2: The `app-article-page` shell

**Files:**
- Create: `src/app/reader/article/article-page/article-page.component.ts`, `.html`, `.scss`
- Test: `src/app/reader/article/article-page/article-page.component.spec.ts`
- Delete: `src/app/reader/article/article-page/_article-page.scss`, `_article-toolbar.scss` (their bodies move into the shell's `.scss`; Tasks 3–4 stop the views importing them — until then keep both files, delete them in Task 4)

**Interfaces:**
- Consumes: everything Task 1 produces.
- Produces: `ArticlePageComponent`, selector `app-article-page`.
  - inputs `fullscreen = input(false)`, `backLabel = input.required<string>()`, `trailing = input<readonly TrailingPart[]>([])`
  - outputs `close`, `openOriginal`
  - public methods `scrollToTop(): void`, `scrollToHeading(id: string): void`, `scrollToElement(target: HTMLElement | null | undefined): void`
  - content slots: `[pageBarLead]`, `[pageBarFlags]`, `[pageByline]`, `[pageMetaTail]`, `[pageHead]`, `[pageCorner]`; template slots `<ng-template articlePageSlot="notices|afterBody|footer">`.

- [ ] **Step 1: Write the failing spec**

`src/app/reader/article/article-page/article-page.component.spec.ts` — a host component fills every slot and provides a stub source:

```ts
import { Component, Injectable, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { installMockResizeObserver } from '../../../../testing/resize-observer-testing';
import { NO_EMBED_DETAILS } from '../../../../testing/embed-details-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { ImageProxyService, ProxyOutcome } from '../../../shared/proxied-image/image-proxy.service';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostActions } from '../composer/post-actions.service';
import { HeroImageDto } from '../../models';
import {
  ArticlePageBody,
  ArticlePageFailure,
  ArticlePageFavicon,
  ArticlePagePaywall,
  ArticlePageSource,
} from './article-page-source';
import { ArticlePageSlotDirective } from './article-page-slot.directive';
import { ArticlePageComponent } from './article-page.component';
import { noAutoplayRegion, provideArticlePage } from './provide-article-page';

@Injectable()
class StubPageSource implements ArticlePageSource {
  readonly key = signal<string | undefined>('link:https://news.test/a');
  readonly url = signal<string | null>('https://news.test/a');
  readonly title = signal('The page title');
  readonly shareTitle = signal('The share title');
  readonly titleHidden = signal(false);
  readonly nameplate = signal('news.test');
  readonly favicon = signal<ArticlePageFavicon | null>(null);
  readonly body = signal<ArticlePageBody | null>({
    html: '<h2 id="one">One</h2><p>Body words</p>',
    pageUrl: 'https://news.test/a',
  });
  readonly bodyAudio = signal(null);
  readonly lead = signal<HeroImageDto | null>(null);
  readonly paywall = signal<ArticlePagePaywall | null>(null);
  readonly loading = signal(false);
  readonly canRefresh = signal(true);
  readonly failure = signal<ArticlePageFailure | null>(null);
  readonly socialPost = signal(false);
  readingAids = true;
  readonly dropLead = jest.fn();
  readonly reload = jest.fn();
}

@Component({
  imports: [ArticlePageComponent, ArticlePageSlotDirective],
  providers: [
    StubPageSource,
    provideArticlePage({ source: StubPageSource, autoplayRegion: noAutoplayRegion }),
  ],
  template: `
    <app-article-page backLabel="Back" (openOriginal)="opened = opened + 1">
      <button pageBarLead class="lead-probe" type="button">Lead</button>
      <button pageBarFlags class="flags-probe" type="button">Flags</button>
      <span pageByline class="byline-probe">The byline</span>
      <span pageMetaTail class="tail-probe">The tail</span>
      <div pageHead class="head-probe">Head</div>
      <ng-template articlePageSlot="notices"><p class="notices-probe">Notices</p></ng-template>
      <ng-template articlePageSlot="afterBody"><p class="after-probe">After</p></ng-template>
      <ng-template articlePageSlot="footer"><p class="footer-probe">Footer</p></ng-template>
      <span pageCorner class="corner-probe">Corner</span>
    </app-article-page>
  `,
})
class HostComponent {
  opened = 0;
}

describe('ArticlePageComponent', () => {
  let fixture: ComponentFixture<HostComponent>;
  let source: StubPageSource;

  beforeEach(() => {
    installMockResizeObserver();
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [
        provideAccountIdentity(),
        NO_EMBED_DETAILS,
        { provide: ListAutoplayService, useValue: { enabled: signal(false) } },
        { provide: SubscriptionsStore, useValue: { socialAccounts: signal([]) } },
        { provide: PostActions, useValue: {} },
        {
          provide: ImageProxyService,
          useValue: { recover: jest.fn<Promise<ProxyOutcome>, []>().mockResolvedValue('failed') },
        },
      ],
    });
  });

  function mount(configure: (stub: StubPageSource) => void = () => undefined): HTMLElement {
    fixture = TestBed.createComponent(HostComponent);
    source = fixture.debugElement.injector.get(StubPageSource);
    configure(source);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const rerender = (): void => fixture.detectChanges();

  it('heads the page with the source’s title, nameplate and meta line', () => {
    const element = mount();

    expect(element.querySelector('h1.title')!.textContent!.trim()).toBe('The page title');
    expect(element.querySelector('.bar-title')!.textContent!.trim()).toBe('news.test');
    expect(element.querySelector('.meta .byline-probe')).not.toBeNull();
    expect(element.querySelector('.meta .tail-probe')).not.toBeNull();
    const original = element.querySelector<HTMLAnchorElement>('.meta a[href="https://news.test/a"]')!;
    expect(original.textContent).toContain('Open original');
  });

  it('keeps a hidden title for screen readers only', () => {
    const element = mount((stub) => stub.titleHidden.set(true));

    expect(element.querySelector('h1.title')!.classList).toContain('sr-only');
  });

  it('reports the open-original click', () => {
    const element = mount();

    element.querySelector<HTMLAnchorElement>('.meta a[href="https://news.test/a"]')!.click();

    expect(fixture.componentInstance.opened).toBe(1);
  });

  it('puts the lead, refresh, share and flags in the bar in that order', () => {
    const element = mount();

    const nav = element.querySelector('.nav')!;
    const order = [...nav.children].map((child) => child.className || child.tagName.toLowerCase());
    expect(order[0]).toContain('lead-probe');
    expect(order.at(-1)).toContain('flags-probe');
    expect(nav.querySelector('[aria-label="Reload article"]')).not.toBeNull();
    expect(nav.querySelector('app-share-button')).not.toBeNull();
  });

  it('refreshes through the source, and hides refresh when the source cannot', () => {
    const element = mount();
    element.querySelector<HTMLButtonElement>('.nav [aria-label="Reload article"]')!.click();
    expect(source.reload).toHaveBeenCalledTimes(1);

    source.canRefresh.set(false);
    rerender();
    expect(element.querySelector('.nav [aria-label="Reload article"]')).toBeNull();
  });

  it('renders the body, then the after-body slot, and the footer below', () => {
    const element = mount();

    const article = element.querySelector('article')!;
    const content = article.querySelector('.content')!;
    expect(content.textContent).toContain('Body words');
    const after = article.querySelector('.after-probe')!;
    const footer = article.querySelector('.footer-probe')!;
    expect(content.compareDocumentPosition(after) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(after.compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('holds the gated parts back while loading, but not the footer', () => {
    const element = mount((stub) => stub.loading.set(true));

    expect(element.querySelector('.content')).toBeNull();
    expect(element.querySelector('.notices-probe')).toBeNull();
    expect(element.querySelector('.after-probe')).toBeNull();
    expect(element.querySelector('.footer-probe')).not.toBeNull();
    expect(element.querySelector('article')!.getAttribute('aria-busy')).toBe('true');
  });

  it('renders no body element when the source has none', () => {
    const element = mount((stub) => stub.body.set(null));

    expect(element.querySelector('.content')).toBeNull();
  });

  it('shows a failure with its detail and a retry that reloads', () => {
    const element = mount((stub) =>
      stub.failure.set({
        messageKey: 'reader.linkFailed',
        quiet: false,
        detail: 'HTTP 503',
        offersOriginal: false,
      }),
    );

    const box = element.querySelector('app-warning-box.reader-fallback')!;
    expect(box.querySelector('.reader-error pre')!.textContent).toBe('HTTP 503');
    box.querySelector<HTMLButtonElement>('button.note-link')!.click();
    expect(source.reload).toHaveBeenCalledTimes(1);
    expect(element.querySelector('a.open-original')).toBeNull();
  });

  it('shows a quiet failure as a plain note', () => {
    const element = mount((stub) =>
      stub.failure.set({ messageKey: 'reader.fallback.empty', quiet: true, detail: 'x', offersOriginal: false }),
    );

    expect(element.querySelector('app-warning-box')).toBeNull();
    expect(element.querySelector('p.reader-fallback-quiet button.note-link')).not.toBeNull();
    expect(element.querySelector('.reader-error')).toBeNull();
  });

  it('offers the original after the lead image when the failure asks for it', () => {
    const element = mount((stub) => {
      stub.failure.set({ messageKey: 'reader.linkFailed', quiet: false, detail: null, offersOriginal: true });
      stub.lead.set({ url: 'https://news.test/lead.jpg', width: null, height: null });
    });

    const lead = element.querySelector('img.lead-image')!;
    const original = element.querySelector('a.open-original')!;
    expect(original.getAttribute('href')).toBe('https://news.test/a');
    expect(lead.compareDocumentPosition(original) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('drops a lead image that fails to load', () => {
    const element = mount((stub) => stub.lead.set({ url: 'https://news.test/lead.jpg', width: 4, height: 3 }));

    const lead = element.querySelector<HTMLImageElement>('img.lead-image')!;
    expect(lead.getAttribute('width')).toBe('4');
    lead.dispatchEvent(new Event('error'));
    expect(source.dropLead).toHaveBeenCalled();
  });

  it('notes a paywall', () => {
    const element = mount((stub) => stub.paywall.set({ url: 'https://news.test/a' }));

    expect(element.querySelector('app-paywall-notice.paywall-note')).not.toBeNull();
  });

  it('puts the share disc before the view’s corner parts while reading aids are on', () => {
    const element = mount();

    const corner = element.querySelector('.corner-actions')!;
    const share = corner.querySelector('app-share-button')!;
    const probe = corner.querySelector('.corner-probe')!;
    expect(share.compareDocumentPosition(probe) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('leaves out the corner disc, TOC and reading time without reading aids', () => {
    const element = mount((stub) => (stub.readingAids = false));

    expect(element.querySelector('.corner-actions app-share-button')).toBeNull();
    expect(element.querySelector('app-reader-toc')).toBeNull();
    expect(element.querySelector('.meta')!.textContent).not.toContain('≈');
  });

  it('jumps to a heading through the page', () => {
    mount();
    const page = fixture.debugElement.query(By.directive(ArticlePageComponent))
      .componentInstance as ArticlePageComponent;
    const scroller = fixture.nativeElement.querySelector('.scroller') as HTMLElement;
    const scrollTo = jest.fn();
    scroller.scrollTo = scrollTo;

    page.scrollToHeading('one');

    expect(scrollTo).toHaveBeenCalled();
  });
});
```

`provideTranslocoTesting` loads the shipped `frontend/public/i18n/en.json`, where `reader.refreshArticle` is "Reload article", `reader.openOriginal` "Open original" and `reader.readingTime` "≈ {{minutes}} min".

- [ ] **Step 2: Run it to see it fail**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/article-page/article-page.component.spec.ts; echo EXIT=$?
```

Expected: FAIL — `./article-page.component` cannot be found.

- [ ] **Step 3: Write the shell's class**

`src/app/reader/article/article-page/article-page.component.ts`:

```ts
import {
  Component,
  ElementRef,
  computed,
  contentChildren,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { LoadingOverlayComponent } from '../../../shared/loading-overlay/loading-overlay.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { prefersReducedMotion } from '../../../core/reduced-motion';
import { LayoutService } from '../../layout.service';
import { ArticleLayerComponent } from '../article-layer/article-layer.component';
import { ArticleContentComponent } from '../article-content/article-content.component';
import { PaywallNoticeComponent } from '../paywall-notice/paywall-notice.component';
import { ReaderTocComponent } from '../reader-toc/reader-toc.component';
import { ShareButtonComponent } from '../share/share-button.component';
import { SelectionShareComponent } from '../selection-share/selection-share.component';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ReadingScope, type TrailingPart } from '../reading/reading-scope.service';
import { TocEntry, collectToc } from '../reading/reading-toc';
import { estimateReadingMinutes } from '../decorators/reading-time';
import { ARTICLE_PAGE_SOURCE } from './article-page-source';
import { ArticlePageSlotDirective, ArticlePageSlotName } from './article-page-slot.directive';

/** One article page — its layer, skeleton and reading aids — filled by a view through its source and slots. */
@Component({
  selector: 'app-article-page',
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    ProxiedImageDirective,
    IconComponent,
    ListActionDirective,
    FaviconComponent,
    LoadingOverlayComponent,
    WarningBoxComponent,
    PaywallNoticeComponent,
    ReaderTocComponent,
    ArticleLayerComponent,
    ArticleContentComponent,
    ShareButtonComponent,
    SelectionShareComponent,
  ],
  templateUrl: './article-page.component.html',
  styleUrl: './article-page.component.scss',
})
export class ArticlePageComponent {
  readonly fullscreen = input(false);
  readonly backLabel = input.required<string>();
  readonly trailing = input<readonly TrailingPart[]>([]);

  readonly openOriginal = output<void>();
  // Semantic "back" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  protected readonly source = inject(ARTICLE_PAGE_SOURCE);
  protected readonly scope = inject(ReadingScope);
  private readonly restore = inject(ArticleScrollRestore);
  private readonly reduceMotion = prefersReducedMotion();

  private readonly slots = contentChildren(ArticlePageSlotDirective);
  protected readonly notices = this.slotNamed('notices');
  protected readonly afterBody = this.slotNamed('afterBody');
  protected readonly footer = this.slotNamed('footer');

  private readonly articleContent = viewChild(ArticleContentComponent);
  private readonly content = computed(() => this.articleContent()?.element);
  private readonly titleHeading = viewChild<ElementRef<HTMLElement>>('titleHeading');
  private readonly layer = viewChild(ArticleLayerComponent);
  private readonly scroller = computed(() => this.layer()?.scroller());
  private readonly restoreKey = computed(() =>
    this.source.readingAids ? this.source.key() : undefined,
  );

  protected readonly progressRail = new ScrollProgressRail({
    isWide: inject(LayoutService).isWide,
    contentBottom: this.scope.readingEnd,
    layout: this.scope.readingEnd,
  });

  // `tocOpen` lives here, not in the TOC, so it survives the reader/original swap.
  protected readonly toc = signal<TocEntry[]>([]);
  protected readonly tocOpen = signal(false);

  protected readonly readingMinutes = computed(() =>
    this.source.readingAids ? estimateReadingMinutes(this.source.body()?.html ?? '') : null,
  );

  constructor() {
    this.restore.connect(this.scroller);
    effect(() => {
      this.source.key();
      untracked(() => {
        this.restore.arm(this.restoreKey());
        this.toc.set([]);
        this.tocOpen.set(false);
      });
    });
    this.scope.connect({ scroller: this.scroller, content: this.content, trailing: this.trailing });
    this.scope.observeResizes();
    inject(ArticleSelection).connect({
      body: this.content,
      key: this.source.key,
      socialPost: this.source.socialPost,
    });
  }

  scrollToTop(): void {
    this.restore.abort(); // don't let a restore fight the jump
    this.layer()?.scrollToTop(this.titleHeading()?.nativeElement);
  }

  scrollToHeading(id: string): void {
    this.scrollToElement(
      this.content()?.nativeElement.querySelector<HTMLElement>(`#${CSS.escape(id)}`),
    );
  }

  /** Scroll the reading pane to an element, clearing the chrome that covers the scroller. */
  scrollToElement(target: HTMLElement | null | undefined): void {
    const scroller = this.scroller();
    if (!scroller || !target) return;
    this.restore.abort(); // a jump takes over from any in-flight restore
    const top =
      target.getBoundingClientRect().top -
      scroller.getBoundingClientRect().top +
      scroller.scrollTop;
    const covered = parseFloat(getComputedStyle(scroller).scrollPaddingTop) || 0;
    scroller.scrollTo({
      top: Math.max(0, top - covered),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
  }

  protected onDecorated(host: HTMLElement): void {
    this.toc.set(collectToc(host));
    this.scope.refresh();
    // Runs on the original render and again when the reader content swaps in.
    this.restore.reseat(this.restoreKey);
  }

  protected onScroll(scrollTop: number): void {
    if (!this.layer()?.leaving()) this.restore.remember(this.restoreKey(), scrollTop);
  }

  private slotNamed(name: ArticlePageSlotName) {
    return computed(
      () => this.slots().find((slot) => slot.name() === name)?.template ?? null,
    );
  }
}
```

The two `// …` comments on `restore.abort()`, the `tocOpen` note, the `onDecorated` note and the `eslint-disable` pair are carried verbatim from `reader-view.component.ts`; carry them, add no others.

- [ ] **Step 4: Write the shell's template**

`src/app/reader/article/article-page/article-page.component.html` — every element below is carried from `reader-view.component.html` / `link-reader.component.html`; keep their classes, attributes and text exactly:

```html
<app-article-layer
  [fullscreen]="fullscreen()"
  [resetKey]="source.key()"
  [progressRail]="progressRail"
  [withTail]="scope.hasTail()"
  [backLabel]="backLabel()"
  (close)="close.emit()"
  (scrolled)="onScroll($event)"
  (toTop)="scrollToTop()"
  (barResized)="scope.refresh()"
>
  <ng-container articleMini>
    @if (source.favicon(); as icon) {
      <app-favicon [url]="icon.url" [avatarUrl]="icon.avatarUrl" [size]="14" />
    }
    <span class="mini-title">{{ source.title() }}</span>
  </ng-container>
  @if (!fullscreen()) {
    <!-- The split pane's toolbar never retracts, so the nameplate rides
         inside it rather than in the full-screen strip of its own.
         aria-hidden: the h1 carries the same title, so announcing this too
         would read the headline twice. -->
    <span articleBar class="bar-name" aria-hidden="true">
      @if (source.favicon(); as icon) {
        <app-favicon [url]="icon.url" [avatarUrl]="icon.avatarUrl" [size]="18" />
      }
      <span class="bar-title">{{ source.nameplate() }}</span>
    </span>
  }
  <div articleBar class="nav">
    <ng-content select="[pageBarLead]" />
    @if (source.canRefresh()) {
      <button
        appListAction
        class="mobile-icon-only"
        type="button"
        [attr.aria-label]="'reader.refreshArticle' | transloco"
        (click)="source.reload()"
      >
        <app-icon name="refresh" size="sm" />
        <span class="txt">{{ 'reader.refreshArticle' | transloco }}</span>
      </button>
    }
    @if (source.url(); as url) {
      <app-share-button [url]="url" [title]="source.shareTitle()" />
    }
    <ng-content select="[pageBarFlags]" />
  </div>
  <article class="text-scaled" [attr.aria-busy]="source.loading() ? 'true' : null">
    <div class="title-row">
      <h1 class="title" [class.sr-only]="source.titleHidden()" #titleHeading tabindex="-1">
        {{ source.title() }}
      </h1>
    </div>
    <p class="meta">
      <ng-content select="[pageByline]" />
      @if (readingMinutes(); as minutes) {
        · {{ 'reader.readingTime' | transloco: { minutes } }}
      }
      @if (source.url(); as url) {
        ·
        <a [href]="url" target="_blank" rel="noopener noreferrer" (click)="openOriginal.emit()"
          >{{ 'reader.openOriginal' | transloco }} <app-icon name="open_in_new" size="text"
        /></a>
      }
      <ng-content select="[pageMetaTail]" />
    </p>
    <ng-content select="[pageHead]" />
    @if (source.readingAids) {
      <app-reader-toc [entries]="toc()" [(open)]="tocOpen" (jump)="scrollToHeading($event)" />
    }
    @if (!source.loading()) {
      @if (source.paywall(); as paywall) {
        <app-paywall-notice class="paywall-note" [url]="paywall.url" />
      }
      @if (source.failure(); as failure) {
        @if (failure.quiet) {
          <p class="reader-fallback-quiet">
            {{ failure.messageKey | transloco }}
            <button type="button" class="note-link reads-as-text" (click)="source.reload()">
              {{ 'reader.retry' | transloco }}
            </button>
          </p>
        } @else {
          <app-warning-box class="reader-fallback">
            <span class="warning-glyph" aria-hidden="true">⚠</span>
            <div class="warning-text">
              <p class="reader-note">
                {{ failure.messageKey | transloco }}
                <button type="button" class="note-link reads-as-text" (click)="source.reload()">
                  {{ 'reader.retry' | transloco }}
                </button>
              </p>
              @if (failure.detail; as detail) {
                <details class="reader-error">
                  <summary>{{ 'reader.showError' | transloco }}</summary>
                  <pre>{{ detail }}</pre>
                </details>
              }
            </div>
          </app-warning-box>
        }
      }
      <ng-container [ngTemplateOutlet]="notices()" />
      @if (source.lead(); as image) {
        <!-- Width/height carry the aspect ratio so the article doesn't jump;
             `height: auto` keeps it from overriding the fluid layout. -->
        <img
          appProxiedImage
          class="lead-image"
          [src]="image.url"
          [attr.width]="image.width"
          [attr.height]="image.height"
          (imageFailed)="source.dropLead()"
          alt=""
          loading="lazy"
          decoding="async"
          referrerpolicy="no-referrer"
        />
      }
      @if (source.failure()?.offersOriginal) {
        @if (source.url(); as url) {
          <a class="open-original" [href]="url" target="_blank" rel="noopener noreferrer"
            >{{ 'reader.openOriginal' | transloco }} <app-icon name="open_in_new" size="sm"
          /></a>
        }
      }
      @if (source.body(); as body) {
        @let audio = source.bodyAudio();
        <app-article-content
          [html]="body.html"
          [pageUrl]="body.pageUrl"
          [enclosureUrl]="audio?.enclosureUrl ?? null"
          [chapters]="audio?.chapters ?? []"
          [audioTrack]="audio?.track ?? null"
          [origin]="audio?.origin ?? null"
          (decorated)="onDecorated($event)"
        />
      }
      <ng-container [ngTemplateOutlet]="afterBody()" />
    }
    <ng-container [ngTemplateOutlet]="footer()" />
  </article>
  <app-selection-share articleDock [url]="source.url()" [title]="source.shareTitle()" />
  <!-- Laid over the whole pane while an article loads or refetches, in place
       of a line of text — the same overlay the feed list raises on reload. -->
  <app-loading-overlay [shown]="source.loading()" [label]="'common.loading' | transloco" />
  @if (source.readingAids) {
    @if (source.url(); as url) {
      <app-share-button articleCorner appearance="corner" [url]="url" [title]="source.shareTitle()" />
    }
  }
  <ng-container articleCorner><ng-content select="[pageCorner]" /></ng-container>
</app-article-layer>
```

If `ng lint` reports the template's cyclomatic complexity over 25, extract the failure note (the `@if (source.failure(); as failure) { … }` block) into `article-page/article-failure-note.component.{ts,html}` (selector `app-article-failure-note`, input `failure = input.required<ArticlePageFailure>()`, output `retry`), keeping its markup and the `reader-fallback*`/`reader-note`/`reader-error` rules with it in its own `.scss` — do not raise the limit.

- [ ] **Step 5: Write the shell's styles**

`src/app/reader/article/article-page/article-page.component.scss`:

```scss
@use '../../../theme/breakpoints' as bp;
@use '../../../theme/glass-rim';

:host {
  display: block;
  height: 100%;
}
```

then paste, below it, the *bodies* of both mixins from `_article-page.scss` and `_article-toolbar.scss` at the top level (drop the `@mixin … {` wrapper lines and their closing braces; keep every rule and its comment), **except** the `.placeholder` rule (it styles the reader view's own empty state and moves there in Task 4). Drop the toolbar partial's header comment ("The nameplate and actions a reader projects … must carry them"), which no longer holds. Then append, from `link-reader.component.scss`:

```scss
/* The page's one way on when it cannot be read here: <app-button variant="primary">'s
   weight, on a link because it leaves the app. */
.open-original {
  display: inline-flex;
  gap: var(--space-1);
  align-items: center;
  height: var(--control-h);
  padding: 0 var(--space-4);
  border-radius: var(--radius);
  background: var(--accent);
  color: var(--on-accent);
  text-decoration: none;
}
```

- [ ] **Step 6: Run the spec, lint and build**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/article-page; echo EXIT=$?
docker compose exec -T frontend npx ng lint; echo EXIT=$?
docker compose exec -T frontend npx ng build; echo EXIT=$?
```

Expected: all PASS, EXIT=0 three times. Fix a failing shell test by fixing the shell, not the test, unless the test contradicts the spec's template order (then report it).

- [ ] **Step 7: Commit**

```bash
git add -A frontend/src/app/reader/article/article-page
git commit -m "refactor(#1983): one article page shell owns the layer, skeleton and reading aids"
```

---

### Task 3: The link reader renders through the shell

**Files:**
- Modify: `src/app/reader/article/link-reader/link-article-source.service.ts`
- Modify: `src/app/reader/article/link-reader/link-reader.component.ts`, `.html`, `.scss`
- Modify: `src/app/reader/shell/reader-shell.component.html:188-195`
- Test: `src/app/reader/article/link-reader/link-reader.component.spec.ts`

**Interfaces:**
- Consumes: `ArticlePageSource` and its value types, `provideArticlePage`, `noAutoplayRegion`, `linkPageKey`, `ArticlePageComponent`.
- Produces: `LinkArticleSource implements ArticlePageSource`; it keeps `title`, `card`, `article`, `errorDetail` public (used by `reader-tab-title.service.ts` and its spec); `LinkReaderComponent` loses its `target` input.

- [ ] **Step 1: Pin the new Retry and the route-keyed page in the spec**

In `link-reader.component.spec.ts`:
- delete every `fixture.componentRef.setInput('target', …)` line (lines ~85, ~205, ~303). In `'remembers the quote while the same link is opened again'` replace its `setInput('target', { ...FIRST_LINK })` with `routeState.linkTarget.set({ ...FIRST_LINK });`.
- add, next to `'offers the reload after a failed load, and reloads the link from it'`:

```ts
  it('retries a failed page from its failure note', () => {
    const fixture = mount();
    reply(fixture, aReaderFailure());
    reloadTarget.mockClear();

    elementOf(fixture)
      .querySelector<HTMLButtonElement>('app-warning-box.reader-fallback button.note-link')!
      .click();

    expect(reloadTarget).toHaveBeenCalledWith(FIRST_LINK);
  });
```

(Use the failure fixture the neighbouring failure tests use — `aReaderFailure()` from `testing/reader-content-testing` or the spec's own constant; match them.)

- [ ] **Step 2: Run it to see the new test fail**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/link-reader; echo EXIT=$?
```

Expected: FAIL in `'retries a failed page from its failure note'` (no `button.note-link`); the others may fail to compile on the removed input until Step 4 — that is expected.

- [ ] **Step 3: Make `LinkArticleSource` a page source**

`src/app/reader/article/link-reader/link-article-source.service.ts`:

```ts
import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Observable } from 'rxjs';
import { HeroImageDto, ReaderContent } from '../../models';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { ReaderContentService } from '../content/reader-content.service';
import { ReaderLoad } from '../content/reader-load';
import { linkPageKey } from '../reading/article-page-key';
import {
  ArticlePageBody,
  ArticlePageBodyAudio,
  ArticlePageFailure,
  ArticlePageFavicon,
  ArticlePagePaywall,
  ArticlePageSource,
} from '../article-page/article-page-source';

/** The page behind the link card the route opens; idle while none is open. */
@Injectable()
export class LinkArticleSource implements ArticlePageSource {
  private readonly reader = inject(ReaderContentService);
  private readonly routeState = inject(ReaderRouteState);
  private readonly load = new ReaderLoad();
  private readonly leadFailed = signal(false);

  readonly card = this.routeState.openLinkCard;
  readonly article = this.load.article;
  readonly errorDetail = this.load.errorDetail;
  readonly loading = this.load.loading;
  readonly readingAids = false;

  readonly key = computed(() => {
    const target = this.routeState.linkTarget();
    return target === null ? undefined : linkPageKey(target.url);
  });
  readonly title = computed(() => this.card()?.title || this.article()?.title || '');
  readonly shareTitle = this.title;
  readonly titleHidden = signal(false).asReadonly();
  readonly nameplate = computed(
    () => this.card()?.siteName ?? this.article()?.siteName ?? '',
  );
  readonly favicon = signal<ArticlePageFavicon | null>(null).asReadonly();
  readonly url = computed(
    () => this.card()?.url ?? this.article()?.url ?? this.load.failure()?.url ?? null,
  );
  readonly body = computed<ArticlePageBody | null>(() => {
    const article = this.article();
    return article === null ? null : { html: article.contentHtml, pageUrl: article.url };
  });
  readonly bodyAudio = signal<ArticlePageBodyAudio | null>(null).asReadonly();
  readonly lead = computed<HeroImageDto | null>(() => {
    const image = this.card()?.imageUrl ?? null;
    if (!this.load.failed() || this.leadFailed() || image === null) return null;
    return { url: image, width: null, height: null };
  });
  readonly paywall = computed<ArticlePagePaywall | null>(() => {
    const article = this.article();
    return article?.paywalled ? { url: article.url } : null;
  });
  readonly canRefresh = computed(() => this.article() !== null || this.load.failed());
  readonly failure = computed<ArticlePageFailure | null>(() =>
    this.load.failed()
      ? { messageKey: 'reader.linkFailed', quiet: false, detail: this.errorDetail(), offersOriginal: true }
      : null,
  );
  readonly socialPost = signal(false).asReadonly();

  constructor() {
    effect(() => {
      const target = this.routeState.linkTarget();
      untracked(() => (target === null ? this.load.idle() : this.run(this.reader.load(target))));
    });
  }

  dropLead(): void {
    this.leadFailed.set(true);
  }

  reload(): void {
    const target = this.routeState.linkTarget();
    if (target !== null) this.run(this.reader.reload(target));
  }

  private run(source: Observable<ReaderContent>): void {
    this.leadFailed.set(false);
    this.load.run(source);
  }
}
```

Check before writing: `grep -rn "LinkArticleSource\|\.failed()\|\.leadFailed\|\.lead()\|\.failure()" frontend/src/app/reader --include='*.ts'` — anything outside `link-reader/` that read `failed`, `failure`, `lead` or `leadFailed` on this class must move to the new members (`reader-tab-title.service.ts` reads only `title`). `link-article-source.service.spec.ts` reads `article()`, which stays.

Compare with the old `siteName`/`originalUrl`/`title` in `link-reader.component.ts` — `nameplate` and `url` must equal them exactly.

- [ ] **Step 4: Slim the link reader to the shell**

`link-reader.component.ts`:

```ts
import { Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ArticlePageComponent } from '../article-page/article-page.component';
import { noAutoplayRegion, provideArticlePage } from '../article-page/provide-article-page';
import { LinkArticleSource } from './link-article-source.service';

/** Where the back button returns: the article the card sits in, or the list. */
export type LinkReaderReturn = 'article' | 'list';

/** A link card's page in the reader, with only the chrome a page that is no entry has. */
@Component({
  selector: 'app-link-reader',
  imports: [TranslocoPipe, ArticlePageComponent],
  providers: provideArticlePage({ source: LinkArticleSource, autoplayRegion: noAutoplayRegion }),
  templateUrl: './link-reader.component.html',
  styleUrl: './link-reader.component.scss',
})
export class LinkReaderComponent {
  readonly fullscreen = input(false);
  readonly returnsTo = input.required<LinkReaderReturn>();

  // Semantic "back" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  protected readonly source = inject(LinkArticleSource);
  protected readonly backLabelKey = computed(() =>
    this.returnsTo() === 'article' ? 'reader.backToArticle' : 'reader.backToList',
  );
}
```

`LinkArticleSource` itself stays provided by `ReaderShellComponent` (`reader-shell.component.ts:110`), where the tab title reads it too; `provideArticlePage` maps the token onto that instance with `useExisting`. The spec's TestBed already provides it.

`link-reader.component.html`:

```html
<app-article-page
  [fullscreen]="fullscreen()"
  [backLabel]="backLabelKey() | transloco"
  (close)="close.emit()"
>
  <span pageByline>{{ source.nameplate() }}</span>
</app-article-page>
```

The meta line's separator before "Open original" is now always drawn; an empty site name leaves a leading "·" — accepted (both card and page lacking a site name).

`link-reader.component.scss` becomes:

```scss
:host {
  display: block;
  height: 100%;
}
```

`reader-shell.component.html` lines ~188–195: delete the `[target]="target"` binding on `<app-link-reader>`; keep the surrounding `@if (linkTarget(); as target)` (it still decides whether the pane exists). If `target` is then unused in that block, change it to `@if (linkTarget())`.

- [ ] **Step 5: Run the specs and build**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/link-reader src/app/reader/shell; echo EXIT=$?
docker compose exec -T frontend npx ng build; echo EXIT=$?
```

Expected: all PASS (including every pre-existing link-reader test, unedited apart from the `setInput('target')` removals); build EXIT=0.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1983): the link reader renders through the article page"
```

---

### Task 4: The reader view renders through the shell

**Files:**
- Create: `src/app/reader/article/reader-view/entry-article-page.ts`
- Modify: `src/app/reader/article/reader-view/reader-view.component.ts`, `.html`, `.scss`
- Test: `src/app/reader/article/reader-view/reader-view.component.spec.ts` (line ~1218 only)
- Delete: `src/app/reader/article/article-page/_article-page.scss`, `_article-toolbar.scss`

**Interfaces:**
- Consumes: Task 1's contract and providers, Task 2's `ArticlePageComponent` (`scrollToElement`, `scrollToHeading`), `ArticlePageSlotDirective`.
- Produces: `EntryArticlePage implements ArticlePageSource` with `connect(entry: Signal<EntryDto | null>): void` and a public `audio: EntryAudio`.

- [ ] **Step 1: Retarget the one spec line that calls a moved method**

`reader-view.component.spec.ts` line ~1218: `fixture.componentInstance.scrollToHeading(heading.id);` becomes

```ts
    (
      fixture.debugElement.query(By.directive(ArticlePageComponent))
        .componentInstance as ArticlePageComponent
    ).scrollToHeading(heading.id);
```

and import `ArticlePageComponent` from `'../article-page/article-page.component'`. No other line of this spec changes.

- [ ] **Step 2: Run the reader-view spec to see it fail to compile**

```bash
docker compose exec -T frontend npx jest src/app/reader/article/reader-view; echo EXIT=$?
```

Expected: FAIL — the page component is not in the reader view's tree yet.

- [ ] **Step 3: Write the adapter**

`src/app/reader/article/reader-view/entry-article-page.ts`:

```ts
import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { AudioPlayerService } from '../../audio-player.service';
import { EntryAudio } from '../../audio/entry-audio';
import { ListenLauncher } from '../../audio/listen-launcher';
import { embedTrackOrigin } from '../../audio/embed-track';
import { entryDisplayTitle, isPost } from '../../entry/entry-text';
import { EntryDto } from '../../models';
import { ArticleSource } from '../content/article-source.service';
import { ReaderModeService } from '../content/reader-mode.service';
import { entryPageKey } from '../reading/article-page-key';
import {
  ArticlePageBody,
  ArticlePageBodyAudio,
  ArticlePageFailure,
  ArticlePageFavicon,
  ArticlePagePaywall,
  ArticlePageSource,
} from '../article-page/article-page-source';

/** The open entry as an article page. */
@Injectable()
export class EntryArticlePage implements ArticlePageSource {
  private readonly article = inject(ArticleSource);
  private readonly readerMode = inject(ReaderModeService);
  private entry: Signal<EntryDto | null> = signal(null);

  /** The entry's first playable audio enclosure, surfaced as a listen control
   *  above the article; its attachment is null when the feed declared none (#915). */
  readonly audio = new EntryAudio(
    { entry: () => this.entry(), chapters: this.article.chapters },
    inject(AudioPlayerService),
    inject(ListenLauncher),
  );

  readonly readingAids = true;
  readonly loading = this.article.loading;
  readonly lead = this.article.hero;

  readonly key = computed(() => {
    const entry = this.entry();
    return entry ? entryPageKey(entry.id) : undefined;
  });
  readonly url = computed(() => this.entry()?.url ?? null);
  readonly title = computed(() => {
    const entry = this.entry();
    return entry ? entryDisplayTitle(entry) : '';
  });
  readonly shareTitle = computed(() => this.entry()?.title ?? '');
  readonly titleHidden = computed(() => {
    const entry = this.entry();
    return entry === null || isPost(entry) || !entry.title;
  });
  readonly nameplate = this.title;
  readonly favicon = computed<ArticlePageFavicon | null>(() => {
    const entry = this.entry();
    return entry ? { url: entry.faviconUrl, avatarUrl: entry.poster?.imageUrl ?? null } : null;
  });
  readonly body = computed<ArticlePageBody>(() => ({
    html: this.article.displayHtml(),
    pageUrl: this.entry()?.url ?? null,
  }));
  readonly bodyAudio = computed<ArticlePageBodyAudio>(() => {
    const entry = this.entry();
    return {
      enclosureUrl: this.audio.attachment()?.url ?? null,
      chapters: this.article.chapters(),
      track: this.audio.track(),
      origin: entry ? embedTrackOrigin(entry) : null,
    };
  });
  readonly paywall = computed<ArticlePagePaywall | null>(() =>
    this.article.paywalled() ? { url: this.article.paywallUrl() } : null,
  );
  readonly canRefresh = computed(() => this.readerMode.mode() === 'reader');
  readonly socialPost = computed(() => this.entry()?.social != null);

  /** Summary plus player is the whole of an audio-first entry whose page holds no article (#1428). */
  private readonly fallbackShown = computed(
    () =>
      this.article.failed() &&
      this.readerMode.mode() === 'original' &&
      this.article.failureReason() !== 'player_page' &&
      !(this.audio.attachment() && this.article.pageHoldsNoArticle()),
  );

  readonly failure = computed<ArticlePageFailure | null>(() => {
    if (!this.fallbackShown()) return null;
    const reason = this.article.failureReason();
    return {
      messageKey: `reader.fallback.${reason ?? 'transport'}`,
      quiet: reason === 'empty',
      detail: this.article.errorDetail(),
      offersOriginal: false,
    };
  });

  connect(entry: Signal<EntryDto | null>): void {
    this.entry = entry;
  }

  dropLead(): void {
    this.article.heroFailed.set(true);
  }

  /** Drop the open article from the browser cache and refetch it. */
  reload(): void {
    const entry = this.entry();
    if (entry) this.article.reload(entry.id);
  }
}
```

Check against the old reader view before moving on: `fallbackShown` equals the old `fallbackNoticeShown`; the quiet variant was `source.failureReason() === 'empty'`; the paywall notice took `source.paywallUrl()` under `source.paywalled()`; the h1's `sr-only` was `isPost(e) || !e.title`; the bar share took `e.title` (raw), the h1/mini/nameplate `entryDisplayTitle(e)`.

- [ ] **Step 4: Rewrite the reader view's class**

In `reader-view.component.ts`:
- `providers` becomes:

```ts
  providers: [
    ArticleSource,
    EntryArticlePage,
    provideArticlePage({ source: EntryArticlePage, autoplayRegion: theArticleComments }),
  ],
```

- `imports`: drop `ProxiedImageDirective`, `LoadingOverlayComponent`, `PaywallNoticeComponent`, `WarningBoxComponent`, `ReaderTocComponent`, `ArticleContentComponent`, `ArticleLayerComponent`, `ShareButtonComponent`, `SelectionShareComponent`, `FaviconComponent`; add `ArticlePageComponent`, `ArticlePageSlotDirective`. Keep the rest the template still uses (check each against the new template below; ESLint/`ng build` will flag unused ones).
- delete these members: `articleContent`, `content`, `titleHeading`, `layer`, `scroller`, `restore`, `scope`, `progressRail`, `audio` (the field — see below), `embedOrigin`, `fallbackNoticeShown`, `fallbackMessageKey`, `pageKey`, `toc`, `tocOpen`, `readingMinutes`, `screen`, `reduceMotion`, `onDecorated`, `onScroll`, `scrollToTop`, `scrollToHeading`, `scrollToElement`, and the `ListAutoplayCoordinator`/`UnlockedVideoElements`/`ReadingScope`/`ArticleSelection`/`ArticleScrollRestore`/`AUTOPLAY_REGION` provider entries.
- add:

```ts
  private readonly page = viewChild(ArticlePageComponent);
  protected readonly entryPage = inject(EntryArticlePage);
  protected readonly audio = this.entryPage.audio;
```

- the constructor becomes:

```ts
  constructor() {
    this.source.connect(this.entry);
    this.entryPage.connect(this.entry);

    effect(() => {
      const entry = this.entry();
      const id = entry?.id ?? null;
      // Only react to a genuine entry change — not to a same-entry reference
      // churn from an optimistic flag update (which must not cancel an in-flight
      // load, re-fetch, or reset the mode toggle).
      if (id === this.loadedId) return;
      this.loadedId = id;
      // Before the source opens: a synchronous load calls enableToggle().
      this.readerMode.reset();
      this.autoplay.soundChanged(false);
      this.source.open(entry);
    });

    effect(() => this.audio.shareChapters());
  }
```

(`autoplay` stays: `private readonly autoplay = inject(ListAutoplayCoordinator);` — it resolves from `provideArticlePage`.)
- `scrollToComments` becomes `this.page()?.scrollToElement(this.commentsSection()?.nativeElement);`
- `refreshArticle()` becomes `this.entryPage.reload();` (keep its docblock).
- keep `commentsRegion`, `reloadSocialCounts`, `retryBody`, `toggleMode`, `when`, `mode`, `loadedId`, the trailing-part queries, `trailingParts`, `commentsForecast`, `comments`, and `theArticleComments`.
- `trailingParts` stays an array; it goes to the page through its `trailing` input.

- [ ] **Step 5: Rewrite the reader view's template**

`reader-view.component.html` — every projected element is the old one, moved:

```html
@if (entry(); as e) {
  <app-article-page
    [fullscreen]="fullscreen()"
    [backLabel]="'reader.backToList' | transloco"
    [trailing]="trailingParts"
    (close)="close.emit()"
    (openOriginal)="openOriginal.emit()"
  >
    @if (readerMode.canToggle()) {
      <button
        pageBarLead
        appListAction
        class="mode"
        type="button"
        [attr.aria-pressed]="mode() === 'reader'"
        [attr.aria-label]="
          (mode() === 'reader' ? 'reader.showOriginal' : 'reader.showReader') | transloco
        "
        (click)="toggleMode()"
      >
        <app-icon [name]="mode() === 'reader' ? 'chrome_reader_mode' : 'public'" size="sm" />
        <span class="txt">{{
          (mode() === 'reader' ? 'reader.readerView' : 'reader.original') | transloco
        }}</span>
      </button>
    }
    <button
      pageBarFlags
      appListAction
      class="mobile-icon-only"
      type="button"
      [attr.aria-label]="'reader.keep' | transloco"
      [appFlagToggle]="e.isKept"
      (click)="actions.keep(e)"
    >
      <app-icon name="bookmark" size="sm" [fill]="e.isKept" />
      <span class="txt">{{ 'reader.keep' | transloco }}</span>
    </button>
    <button
      pageBarFlags
      appListAction
      class="mobile-icon-only"
      type="button"
      [attr.aria-label]="'reader.favorite' | transloco"
      [appFlagToggle]="e.isFavorite"
      (click)="actions.favorite(e)"
    >
      <app-icon name="star" size="sm" [fill]="e.isFavorite" />
      <span class="txt">{{ 'reader.favorite' | transloco }}</span>
    </button>
    <ng-container pageByline>
      <app-entry-source [entry]="e" layout="article" [size]="16" />
      @if (e.author && e.author !== (e.poster?.name ?? e.source)) {
        · {{ e.author }}
      }
      · {{ when(e) }}
    </ng-container>
    <app-entry-discussion-link pageMetaTail [entry]="e" (jump)="scrollToComments()" />
    <ng-container pageHead>
      <app-entry-pills
        class="tags"
        [tags]="tags()"
        [subscriptionId]="e.subscriptionId"
        [savedSearches]="e.savedSearches ?? []"
      />
      <app-entry-actions class="actions" size="md" [entry]="e" [audio]="false" [like]="false" />
      @if (audio.attachment()) {
        <app-listen-row [audio]="audio" />
      }
      <app-episode-panels
        [track]="audio.track()"
        [chapters]="source.chapters()"
        [transcript]="source.transcript()"
        [transcriptIsTheBody]="source.transcriptIsTheBody()"
      />
    </ng-container>
    <ng-template articlePageSlot="notices">
      @if (source.feedBodyFailed()) {
        <app-error-banner
          class="body-error"
          [message]="'reader.bodyLoadFailed' | transloco"
          [actionLabel]="'reader.retry' | transloco"
          (action)="retryBody()"
        />
      }
    </ng-template>
    <ng-template articlePageSlot="afterBody">
      @let media = source.postMedia();
      @if (media.length) {
        <app-post-media
          [media]="media"
          [ratio]="ratio(e, media[0])"
          [videoLabel]="'reader.mediaVideo' | transloco"
        />
      }
      @if (e.share === 'quote' && e.quote) {
        <app-quoted-post [quote]="e.quote" layout="article" />
      }
      @if (e.linkCards.length || e.pending) {
        <app-link-cards [entry]="e" />
      }
      @if (e.thread.length) {
        <app-thread-replies [entry]="e" />
      }
      @if (e.social) {
        <app-social-counts [entry]="e" />
      }
    </ng-template>
    <ng-template articlePageSlot="footer">
      @if (e.categories.length) {
        <p class="categories">{{ e.categories.join(', ') }}</p>
      }
      @if (!source.loading() && e.comments) {
        <app-entry-comments [entry]="e" (refreshed)="reloadSocialCounts()" />
      }
    </ng-template>
    <app-entry-corner-flags pageCorner [entry]="e" />
  </app-article-page>
} @else {
  <div class="placeholder text-scaled">
    <p>{{ 'reader.selectArticle' | transloco }}</p>
  </div>
}
```

Diff it against the old template block by block: the mode button, the two flag buttons, the byline text and separators, pills/actions/listen/episode panels, the body-error banner, the after-body parts and the footer must be character-identical apart from the slot attributes.

- [ ] **Step 6: Move the styles**

`reader-view.component.scss` becomes (drop both `@use` lines and both `@include`s):

```scss
:host {
  display: block;
  height: 100%;
}

.placeholder {
  display: grid;
  place-items: center;
  height: 100%;
  color: var(--text-muted);
}
```

followed by its existing `.categories`, `.tags`, `.actions`, `.body-error` and `app-post-media` rules unchanged (all projected or slotted from this template, so they stay here). Delete `article-page/_article-page.scss` and `_article-toolbar.scss` (`git rm`), after confirming with `grep -rn "article-page'\|article-toolbar'" frontend/src` that nothing imports them.

Then grep the new shell `.scss` for selectors that now target nodes this template renders (`.mode`, `.tags`, `.actions`, `.categories`, `app-entry-*`): a rule there would not reach them. Report any you find; there should be none.

- [ ] **Step 7: Run the reader-view and shell specs, lint and build**

```bash
docker compose exec -T frontend npx jest src/app/reader/article src/app/reader/shell; echo EXIT=$?
docker compose exec -T frontend npx ng lint; echo EXIT=$?
docker compose exec -T frontend npx ng build; echo EXIT=$?
```

Expected: every suite PASS with no reader-view assertion edited; lint and build EXIT=0. The scroll-restore tests in `describe('article scroll restore')` are the guard on the effect order across the two components (the shell arms, the view opens); if one fails, fix the ordering in code, do not change the test.

- [ ] **Step 8: Break-test the slot queries**

Temporarily move `<app-entry-comments …>` out of the `footer` template slot into the `pageHead` container and run the reader-view spec: at least one comments-related test (autoplay region, comments jump or reading-focus trailing part) must still pass or fail for the right reason — the point is to see that the `commentsSection` view query resolves through a template slot at all. Then move it back by editing (no `git checkout --`), rerun, expect PASS. Quote both summary lines in the report.

- [ ] **Step 9: Commit**

```bash
git add -A frontend/src/app/reader
git commit -m "refactor(#1983): the reader view renders through the article page"
```

---

### Task 5: Style parity, gate, PR (controller)

- [ ] **Step 1: Computed-style parity on the real render.** On the #1982 branch tip and on this branch, run one throwaway Playwright spec (stubbed API, desktop 1280×900 and phone 375×812) that records `getComputedStyle` (`color`, `font-size`, `margin`, `padding`, `display`, `vertical-align` of icons) for: `h1.title`, `.meta`, `.meta a`, `.meta a app-icon`, `a.discussion-link app-icon`, `.lead-image`, `.reader-fallback`, `.reader-error pre`, `.bar-name`, `.bar-title`, `.mini-title`, `.nav`, `.mode`, `.tags`, `.actions`, `.categories`, `.corner-actions button`, and in the link reader `.open-original`, `.meta`. Both runs must match. Delete the spec afterwards.
- [ ] **Step 2: Full gate alone:** `npm run check`, `ng build` (EXIT=0, quote `Test Suites:`/`Tests:`).
- [ ] **Step 3: Live smokes:** the Playwright article smokes (reader-smoke, listen-to-embed, body-islands, link-card-reader, article-back-desktop, article-reading-progress, article-mini-header, article-slide-out, article-tail-space, chapters, social-counts) — the known social-counts failure on `develop` aside, all pass.
- [ ] **Step 4: `/simplify`**, then the gate again if anything changed.
- [ ] **Step 5: Push and open the PR** into `refactor/1982-article-page-groundwork` while #1985 is open (into `develop` once it is merged), title `Article views: both views render through one ArticlePage shell`, body `Closes #1983`, `Refs #1978`, naming the boy-scout `CLAUDE.md` commit and the link reader's new Retry.
