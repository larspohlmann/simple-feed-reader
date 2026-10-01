import { Signal, WritableSignal, signal } from '@angular/core';
import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { of, Subject, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import { ReaderViewComponent } from './reader-view.component';
import { ArticleGestures } from './article-gestures.service';
import { ReaderContentService } from '../content/reader-content.service';
import { EntryBodyService, EntryBodyState } from '../content/entry-body.service';
import { entryScrollKey } from '../../scroll/list-scroll-memory';
import { EntryDto, ReaderArticle, ReaderContent, ReaderFailure } from '../../models';
import { ReaderModeService } from '../content/reader-mode.service';
import { ReadingFocusService } from '../../../core/preferences/reading-focus.service';
import { AudioPlayerService } from '../../audio-player.service';
import { CommentsService, CommentsState } from '../entry-comments/comments.service';
import { ImageProxyService, ProxyOutcome } from '../../../shared/proxied-image/image-proxy.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

/** A controllable double for the real, HTTP-backed store: `entry-body.service.spec.ts`
 *  covers caching/dedup/eviction; this file only needs to drive what the view renders. */
class FakeEntryBodyService {
  private readonly states = new Map<number, WritableSignal<EntryBodyState>>();
  private readonly defaults = new Map<number, string | null>();
  readonly retriedIds: number[] = [];

  body(id: number): Signal<EntryBodyState> {
    return this.stateFor(id).asReadonly();
  }

  prefetch(id: number): void {
    this.stateFor(id);
  }

  seed(id: number, html: string | null): void {
    this.stateFor(id).set({ status: 'ok', html });
  }

  retry(id: number): void {
    this.retriedIds.push(id);
    this.stateFor(id).set({ status: 'ok', html: 'RETRIED' });
  }

  /** What `body(id)` answers before anything else sets it — the stand-in for
   *  the real store's first fetch landing. */
  setDefault(id: number, html: string | null): void {
    this.defaults.set(id, html);
  }

  setLoading(id: number): void {
    this.stateFor(id).set({ status: 'loading' });
  }

  setError(id: number): void {
    this.stateFor(id).set({ status: 'error' });
  }

  private stateFor(id: number): WritableSignal<EntryBodyState> {
    let state = this.states.get(id);
    if (!state) {
      state = signal<EntryBodyState>({ status: 'ok', html: this.defaults.get(id) ?? null });
      this.states.set(id, state);
    }
    return state;
  }
}

class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: ResizeObserverCallback) {
    MockResizeObserver.instances.push(this);
  }
  observe(target: Element): void {
    this.targets.add(target);
  }
  unobserve(target: Element): void {
    this.targets.delete(target);
  }
  disconnect(): void {
    this.targets.clear();
  }
  fire(): void {
    this.callback([], this as unknown as ResizeObserver);
  }
}

/** What the body store answers for entry 1 by default (see `beforeEach` below),
 *  so most of these presentational tests need no body-store setup of their own. */
const gestures = (fixture: ComponentFixture<ReaderViewComponent>): ArticleGestures =>
  fixture.debugElement.injector.get(ArticleGestures);

const scrollerOf = (fixture: ComponentFixture<ReaderViewComponent>): HTMLElement =>
  (fixture.nativeElement as HTMLElement).querySelector('.scroller') as HTMLElement;

const DEFAULT_BODY = '<p>Body</p><a href="https://ext.test/z">link</a>';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Deep dive',
  url: 'https://x/1',
  author: 'Ada',
  summary: null,
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'Ars',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

/** An entry whose feed body is `html`, for tests that care what the original
 *  view renders — the feed-mode analogue of passing `contentHtml` directly. */
function entryWithBody(html: string | null, over: Partial<EntryDto> = {}): EntryDto {
  const built = entry(over);
  fakeBody.setDefault(built.id, html);
  return built;
}

let loadMock: jest.Mock;
let reloadMock: jest.Mock;
let fakeBody: FakeEntryBodyService;

function mount(testEntry: EntryDto | null) {
  const fixture = TestBed.createComponent(ReaderViewComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

const okContent = (over: Partial<ReaderArticle> = {}): ReaderArticle => ({
  status: 'ok',
  contentHtml: '<p>READER</p>',
  url: '',
  title: '',
  byline: null,
  siteName: null,
  excerpt: null,
  originalHero: null,
  extractedAt: '',
  paywalled: false,
  ...over,
});

const failedContent = (over: Partial<ReaderFailure> = {}): ReaderFailure => ({
  status: 'failed',
  reason: 'fetch',
  detail: null,
  url: null,
  originalHero: null,
  ...over,
});

function stubComments(state: Signal<CommentsState>): void {
  TestBed.overrideProvider(CommentsService, {
    useValue: { state: () => state, load: jest.fn(), reload: jest.fn() },
  });
}

describe('ReaderViewComponent', () => {
  let imageProxy: { recover: jest.Mock<Promise<ProxyOutcome>, [HTMLImageElement]> };

  beforeEach(() => {
    imageProxy = { recover: jest.fn().mockResolvedValue('failed') };
    localStorage.clear();
    sessionStorage.clear();
    MockResizeObserver.instances = [];
    (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
    // Default: extraction fails so the existing presentational tests keep
    // asserting against the feed's own content. Reader-specific tests override.
    loadMock = jest.fn(() => of<ReaderContent>(failedContent()));
    reloadMock = jest.fn(() => of<ReaderContent>(okContent()));
    fakeBody = new FakeEntryBodyService();
    fakeBody.setDefault(1, DEFAULT_BODY);
    TestBed.configureTestingModule({
      imports: [ReaderViewComponent, provideTranslocoTesting()],
      providers: [
        { provide: EntryActionHandler, useValue: entryActions },
        provideRouter([]),
        { provide: ReaderContentService, useValue: { load: loadMock, reload: reloadMock } },
        { provide: EntryBodyService, useValue: fakeBody },
        { provide: ImageProxyService, useValue: imageProxy },
      ],
    });
  });

  describe('reading focus setting', () => {
    it('clears dimming from the open article when disabled', async () => {
      const fixture = mount(entryWithBody('<p>First</p><p>Second</p>'));
      await Promise.resolve();
      fixture.detectChanges();
      const blocks = Array.from(
        (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>('.content > *'),
      );
      for (const block of blocks) block.style.opacity = '0.28';

      TestBed.inject(ReadingFocusService).setEnabled(false);
      fixture.detectChanges();

      expect(blocks.map((block) => block.style.opacity)).toEqual(['', '']);
    });

    it('restores dimming in the open article when enabled again', async () => {
      const readingFocus = TestBed.inject(ReadingFocusService);
      readingFocus.setEnabled(false);
      const fixture = mount(entryWithBody('<p>First</p><p>Second</p>'));
      await Promise.resolve();
      fixture.detectChanges();
      const blocks = Array.from(
        (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>('.content > *'),
      );
      expect(blocks.map((block) => block.style.opacity)).toEqual(['', '']);

      readingFocus.setEnabled(true);
      fixture.detectChanges();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      expect(blocks.map((block) => block.style.opacity)).not.toContain('');
    });
  });

  it('renders title, meta, content and decorates external links', async () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.title')!.textContent).toContain('Deep dive');
    expect(element.querySelector('.meta')!.textContent).toContain('Ars');
    expect(element.querySelector('.content')!.textContent).toContain('Body');
    await Promise.resolve(); // link decoration runs in a microtask
    const link = element.querySelector('.content a') as HTMLAnchorElement;
    expect(link.target).toBe('_blank');
    expect(link.rel).toContain('noopener');
  });

  it('leaves in-page fragment anchors undecorated', async () => {
    const element = mount(
      entryWithBody('<a href="#footnote">jump</a><a href="https://ext.test/z">ext</a>'),
    ).nativeElement as HTMLElement;
    await Promise.resolve(); // link decoration runs in a microtask
    const anchors = element.querySelectorAll('.content a');
    expect((anchors[0] as HTMLAnchorElement).target).toBe(''); // fragment link untouched
    expect((anchors[1] as HTMLAnchorElement).target).toBe('_blank'); // external decorated
  });

  describe('reading time', () => {
    const longBody = `<p>${Array.from({ length: 660 }, (_, index) => `w${index}`).join(' ')}</p>`;

    it('shows the estimate for a long article', () => {
      const fixture = mount(entryWithBody(longBody));

      expect(fixture.nativeElement.querySelector('.meta')?.textContent).toContain('≈ 3 min');
    });

    it('hides the estimate for a short article', () => {
      const fixture = mount(entryWithBody('<p>Tiny.</p>'));

      expect(fixture.nativeElement.querySelector('.meta')?.textContent).not.toContain('≈');
    });
  });

  describe('table of contents', () => {
    const threeHeadings = '<h2>Alpha</h2><p>a</p><h2>Beta</h2><p>b</p><h3>Gamma</h3>';

    it('shows a table of contents, collapsed by default, for articles with several headings', async () => {
      const fixture = mount(entryWithBody(threeHeadings));
      await Promise.resolve(); // content-processing microtask builds the TOC
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;
      const toggle = element.querySelector('.toc-toggle') as HTMLButtonElement;
      expect(toggle).not.toBeNull();
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(element.querySelectorAll('.toc-list li').length).toBe(0); // collapsed

      toggle.click();
      fixture.detectChanges();
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
      const items = [...element.querySelectorAll('.toc-list button')].map((button) =>
        button.textContent?.trim(),
      );
      expect(items).toEqual(['Alpha', 'Beta', 'Gamma']);
    });

    it('gives the headings unique ids so the TOC can jump to them', async () => {
      const fixture = mount(entryWithBody('<h2>Same</h2><h2>Same</h2><h2>Same</h2>'));
      await Promise.resolve();
      fixture.detectChanges();
      const ids = [...(fixture.nativeElement as HTMLElement).querySelectorAll('.content h2')].map(
        (heading) => (heading as HTMLElement).id,
      );
      expect(ids.every((id) => id.length > 0)).toBe(true);
      expect(new Set(ids).size).toBe(3); // deduped
    });

    it('omits the TOC for short articles', async () => {
      const fixture = mount(entryWithBody('<h2>Only one</h2><p>x</p>'));
      await Promise.resolve();
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('.toc')).toBeNull();
    });
  });

  describe('back-to-top button', () => {
    function scrollArticleTo(fixture: ReturnType<typeof mount>, top: number): void {
      const scroller = scrollerOf(fixture);
      Object.defineProperty(scroller, 'scrollTop', { configurable: true, value: top });
      scroller.dispatchEvent(new Event('scroll'));
    }

    it('appears only after scrolling down and jumps back to the top on click', () => {
      const fixture = mount(entry());
      const host = fixture.nativeElement as HTMLElement;
      expect(host.querySelector('app-to-top-button')).toBeNull(); // hidden at the top

      scrollArticleTo(fixture, 900);
      fixture.detectChanges();
      const button = host.querySelector('app-to-top-button button') as HTMLButtonElement;
      expect(button).not.toBeNull();

      const scroller = scrollerOf(fixture);
      const scrollTo = jest.fn();
      scroller.scrollTo = scrollTo as unknown as typeof scroller.scrollTo;
      button.click();
      expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }));
    });

    it('hides again when scrolled back near the top', () => {
      const fixture = mount(entry());
      const host = fixture.nativeElement as HTMLElement;
      scrollArticleTo(fixture, 900);
      fixture.detectChanges();
      expect(host.querySelector('app-to-top-button')).not.toBeNull();

      scrollArticleTo(fixture, 20);
      fixture.detectChanges();
      expect(host.querySelector('app-to-top-button')).toBeNull();
    });

    // #98: the button unmounts once showToTop flips false, which would otherwise
    // drop keyboard focus to <body> and strand a keyboard/screen-reader user.
    it('moves focus to the article title instead of dropping it to the body', () => {
      const fixture = mount(entry());
      const host = fixture.nativeElement as HTMLElement;
      scrollArticleTo(fixture, 900);
      fixture.detectChanges();
      const scroller = scrollerOf(fixture);
      scroller.scrollTo = jest.fn() as unknown as typeof scroller.scrollTo;

      (host.querySelector('app-to-top-button button') as HTMLButtonElement).click();

      expect(document.activeElement).toBe(host.querySelector('h1.title'));
    });
  });

  // #101: the restore has to fire on every path that ends with the article
  // rendered — including extraction failure, where the rendered HTML never
  // changes value (mode flips reader -> original but the feed's own content is
  // shown either way), so the content signal alone can never trigger it.
  describe('article scroll restore', () => {
    function trackScrollTop(scroller: HTMLElement): { top: number } {
      const state = { top: 0 };
      Object.defineProperty(scroller, 'scrollTop', {
        configurable: true,
        get: () => state.top,
        set: (value: number) => {
          state.top = value;
        },
      });
      return state;
    }

    /** Mount entry 1 with a remembered offset and extraction still in flight. */
    function mountRemembering(top: number) {
      const load = new Subject<ReaderContent>();
      loadMock.mockReturnValue(load);
      sessionStorage.setItem(entryScrollKey(1), String(top));
      const fixture = TestBed.createComponent(ReaderViewComponent);
      fixture.componentRef.setInput('entry', entry({ id: 1 }));
      fixture.detectChanges();
      const scroll = trackScrollTop(scrollerOf(fixture));
      return { fixture, scroll, load };
    }

    /** Let the content-processing microtask and any follow-up effect settle. */
    async function settle(fixture: { detectChanges(): void }) {
      await Promise.resolve();
      fixture.detectChanges();
      await Promise.resolve();
    }

    it('restores the remembered offset when extraction fails', async () => {
      const { fixture, scroll, load } = mountRemembering(900);
      load.next(failedContent());
      await settle(fixture);
      expect(scroll.top).toBe(900);
      fixture.destroy();
    });

    it('restores the remembered offset when extraction succeeds', async () => {
      const { fixture, scroll, load } = mountRemembering(900);
      load.next(okContent());
      await settle(fixture);
      expect(scroll.top).toBe(900);
      fixture.destroy();
    });

    it('leaves an article with no remembered offset at the top', async () => {
      const { fixture, scroll, load } = mountRemembering(0);
      load.next(failedContent());
      await settle(fixture);
      expect(scroll.top).toBe(0);
      fixture.destroy();
    });
  });

  // #107: the reading focus only fully highlights the block at the viewport
  // centre, so the article needs tail space for its last paragraph to get there.
  describe('tail space below a long article', () => {
    /** Pin the pane's height and where the article's own content box ends. */
    function pinGeometry(
      fixture: ReturnType<typeof mount>,
      contentBottom: number,
      viewport: number,
    ): HTMLElement {
      const host = fixture.nativeElement as HTMLElement;
      const scroller = scrollerOf(fixture);
      Object.defineProperty(scroller, 'clientHeight', { configurable: true, value: viewport });
      scroller.getBoundingClientRect = () => ({ top: 0, bottom: viewport }) as DOMRect;
      const content = host.querySelector('.content') as HTMLElement;
      content.getBoundingClientRect = () => ({ top: 0, bottom: contentBottom }) as DOMRect;
      return host;
    }

    /** Pin the geometry, then re-measure it on a resize, one of the moments the pane does. */
    function stubGeometry(
      fixture: ReturnType<typeof mount>,
      contentBottom: number,
      viewport: number,
    ): HTMLElement {
      const host = pinGeometry(fixture, contentBottom, viewport);
      window.dispatchEvent(new Event('resize'));
      fixture.detectChanges();
      return host;
    }

    it('adds it when the article is taller than the pane', () => {
      const fixture = mount(entry());
      const host = stubGeometry(fixture, 2400, 800);
      expect(host.querySelector('.reader')!.classList).toContain('with-tail');
    });

    it('measures the inner scroller once the article first renders', async () => {
      const fixture = mount(entry());
      const host = pinGeometry(fixture, 2400, 800);

      await Promise.resolve(); // the content-processing microtask
      fixture.detectChanges();

      expect(host.querySelector('.reader')!.classList).toContain('with-tail');
    });

    it('withholds it from an article that fits, which would be dead scroll', () => {
      const fixture = mount(entry());
      const host = stubGeometry(fixture, 400, 800);
      expect(host.querySelector('.reader')!.classList).not.toContain('with-tail');
    });

    it('adds it when the comments carry a short post past the pane (#1150)', () => {
      stubComments(signal<CommentsState>({ status: 'idle' }));
      const fixture = mount(entry({ comments: 'manual' }));
      const comments = (fixture.nativeElement as HTMLElement).querySelector(
        'app-entry-comments',
      ) as HTMLElement;
      comments.getBoundingClientRect = () => ({ top: 400, bottom: 2400 }) as DOMRect;

      const host = stubGeometry(fixture, 400, 800);

      expect(host.querySelector('.reader')!.classList).toContain('with-tail');
      expect(host.querySelector('.progress-rail, .progress')).toBeNull();
    });

    it('re-measures the reading scope when the toolbar’s reservation moves the article', () => {
      const fixture = mount(entry());
      const host = stubGeometry(fixture, 400, 800);
      expect(host.querySelector('.reader')!.classList).not.toContain('with-tail');

      const content = host.querySelector('.content') as HTMLElement;
      content.getBoundingClientRect = () => ({ top: 0, bottom: 2400 }) as DOMRect;
      const bar = host.querySelector('.bar') as HTMLElement;
      MockResizeObserver.instances.find((observer) => observer.targets.has(bar))!.fire();
      fixture.detectChanges();

      expect(host.querySelector('.reader')!.classList).toContain('with-tail');
    });
  });

  it('renders the article’s action row through the shared entry actions', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    const row = element.querySelector('app-entry-actions.actions');
    expect(row).not.toBeNull();
    expect(row!.classList).toContain('glyph-md');
  });

  it('sends favorite/keep/read to the handler and emits close', () => {
    const fixture = mount(entry());
    let closes = 0;
    fixture.componentInstance.close.subscribe(() => closes++);
    const element = fixture.nativeElement as HTMLElement;
    // Scoped to the article's own row: the split pane's toolbar carries a
    // second favourite/keep pair, and it comes first in the DOM.
    (element.querySelector('.actions [aria-label="Favorite"]') as HTMLButtonElement).click();
    (element.querySelector('.actions [aria-label="Keep"]') as HTMLButtonElement).click();
    (element.querySelector('.actions [aria-label="Toggle read"]') as HTMLButtonElement).click();
    (element.querySelector('.close') as HTMLButtonElement).click();
    expect(entryActions.favorite).toHaveBeenCalledTimes(1);
    expect(entryActions.keep).toHaveBeenCalledTimes(1);
    expect(entryActions.toggleRead).toHaveBeenCalledTimes(1);
    expect(closes).toBe(1);
  });

  it('emits openOriginal when the original-article link is clicked', () => {
    const fixture = mount(entry({ url: 'https://example.com/full-story' }));
    const emitted = jest.fn();
    fixture.componentInstance.openOriginal.subscribe(emitted);

    const link = fixture.debugElement.query(By.css('a[target="_blank"]'));
    link.triggerEventHandler('click', null);

    expect(emitted).toHaveBeenCalled();
  });

  it('carries the full-screen back button in its own toolbar, sliding out before close', () => {
    // Full-screen chrome belongs to the article, not the shell's bar (#128):
    // the toolbar rides the overlay, so the list's header underneath never has
    // to change — and the back button plays the slide-out (like a back-swipe)
    // rather than cutting straight to the list, so close waits for it.
    const fixture = TestBed.createComponent(ReaderViewComponent);
    fixture.componentRef.setInput('entry', entry());
    fixture.componentRef.setInput('fullscreen', true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const close = jest.fn();
    fixture.componentInstance.close.subscribe(close);
    const back = element.querySelector('.bar .close') as HTMLButtonElement;
    expect(back).not.toBeNull();
    back.click();
    expect(close).not.toHaveBeenCalled();
    expect(gestures(fixture).leaving()).toBe(true);
    fixture.destroy();
  });

  // The panel reserves the floating app bar's height only in the split pane,
  // where the shell's bar floats above it (#97). Full-screen, the article rides
  // an overlay ABOVE that bar and brings its own toolbar, so a
  // reservation would be a blank strip. jsdom cannot see the resulting layout,
  // so pin the flag the stylesheet keys off instead.
  it('reserves the app bar only in the split pane, not full-screen', () => {
    const withBar = mount(entry()).nativeElement as HTMLElement;
    expect(withBar.querySelector('.frame')!.classList).toContain('with-bar');

    const fixture = TestBed.createComponent(ReaderViewComponent);
    fixture.componentRef.setInput('entry', entry());
    fixture.componentRef.setInput('fullscreen', true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.frame')!.classList).not.toContain('with-bar');
    fixture.destroy();
  });

  // #1332: iOS spends a tap inside a coasting scroller on stopping it.
  it('keeps the chrome a tap must reach outside the article’s scroller', () => {
    const fixture = TestBed.createComponent(ReaderViewComponent);
    fixture.componentRef.setInput('entry', entry());
    fixture.componentRef.setInput('fullscreen', true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const scroller = scrollerOf(fixture);
    scroller.scrollTop = 900;
    scroller.dispatchEvent(new Event('scroll'));
    fixture.detectChanges();

    for (const chrome of ['.mini', '.bar', 'app-to-top-button']) {
      const found = element.querySelector(chrome);
      expect(found).not.toBeNull();
      expect(scroller.contains(found)).toBe(false);
    }
    expect(scroller.contains(element.querySelector('.content'))).toBe(true);
    fixture.destroy();
  });

  it('reserves the toolbar’s measured height above the article', () => {
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    const bar = element.querySelector('.bar') as HTMLElement;
    Object.defineProperty(bar, 'offsetHeight', { configurable: true, value: 44 });

    MockResizeObserver.instances.find((observer) => observer.targets.has(bar))!.fire();

    expect(element.style.getPropertyValue('--reader-bar-h')).toBe('44px');
  });

  it('lands a contents jump below the chrome covering the scroller', async () => {
    const fixture = mount(entryWithBody('<h2>A</h2><p>a</p><h2>B</h2><p>b</p>'));
    await Promise.resolve();
    fixture.detectChanges();
    const scroller = scrollerOf(fixture);
    const heading = scroller.querySelector('.content h2:last-of-type') as HTMLElement;
    heading.getBoundingClientRect = () => ({ top: 700 }) as DOMRect;
    scroller.getBoundingClientRect = () => ({ top: 0 }) as DOMRect;
    const computedStyle = jest
      .spyOn(window, 'getComputedStyle')
      .mockReturnValue({ scrollPaddingTop: '120px' } as CSSStyleDeclaration);
    const scrollTo = jest.fn();
    scroller.scrollTo = scrollTo as unknown as typeof scroller.scrollTo;
    // jsdom ships no `CSS` namespace; the decorator's ids need no escaping.
    const globals = globalThis as unknown as { CSS?: { escape: (value: string) => string } };
    globals.CSS = { escape: (value) => value };

    fixture.componentInstance.scrollToHeading(heading.id);
    computedStyle.mockRestore();
    delete globals.CSS;

    expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 580 }));
  });

  describe('full-screen toolbar hide-on-scroll', () => {
    function fullscreenMount() {
      const fixture = TestBed.createComponent(ReaderViewComponent);
      fixture.componentRef.setInput('entry', entry());
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();
      return fixture;
    }

    function scrollArticleTo(fixture: ReturnType<typeof mount>, top: number): void {
      const scroller = scrollerOf(fixture);
      scroller.scrollTop = top;
      scroller.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();
    }

    it('opens presented, retracts scrolling down, returns scrolling up', () => {
      const fixture = fullscreenMount();
      const bar = (fixture.nativeElement as HTMLElement).querySelector('.bar')!;
      expect(bar.classList).not.toContain('hidden');

      scrollArticleTo(fixture, 400); // down
      expect(bar.classList).toContain('hidden');

      scrollArticleTo(fixture, 300); // up
      expect(bar.classList).not.toContain('hidden');
      fixture.destroy();
    });

    it('presents the toolbar anew for the next entry', () => {
      // prev/next reuse this component instance; a toolbar the previous
      // article's reading had retracted must not open the next one headless.
      const fixture = fullscreenMount();
      scrollArticleTo(fixture, 400);
      expect((fixture.nativeElement as HTMLElement).querySelector('.bar')!.classList).toContain(
        'hidden',
      );

      fixture.componentRef.setInput('entry', entry({ id: 2 }));
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('.bar')!.classList).not.toContain(
        'hidden',
      );
      fixture.destroy();
    });

    it('never retracts the split-pane toolbar', () => {
      const fixture = mount(entry());
      const bar = (fixture.nativeElement as HTMLElement).querySelector('.bar')!;
      scrollArticleTo(fixture, 100);
      scrollArticleTo(fixture, 500);
      expect(bar.classList).not.toContain('hidden');
    });

    it('keeps the mini header while the toolbar below it retracts', () => {
      // The mini header is the only thing naming the article once the toolbar
      // is gone, so it must survive the very scroll that retracts the toolbar.
      const fixture = fullscreenMount();
      const element = fixture.nativeElement as HTMLElement;
      scrollArticleTo(fixture, 400);

      expect(element.querySelector('.bar')!.classList).toContain('hidden');
      expect(element.querySelector('.mini')!.classList).not.toContain('hidden');
      expect(element.querySelector('.mini .mini-title')!.textContent).toContain('Deep dive');
      fixture.destroy();
    });
  });

  describe('mini header', () => {
    it('names the article with its favicon and title', () => {
      const fixture = TestBed.createComponent(ReaderViewComponent);
      fixture.componentRef.setInput('entry', entry({ faviconUrl: 'https://x/f.png' }));
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();

      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('.mini .mini-title')!.textContent).toContain('Deep dive');
      expect(element.querySelector<HTMLImageElement>('.mini app-favicon img')!.src).toBe(
        'https://x/f.png',
      );
      fixture.destroy();
    });

    it('hides itself from assistive technology, which reads the h1 instead', () => {
      const fixture = TestBed.createComponent(ReaderViewComponent);
      fixture.componentRef.setInput('entry', entry());
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();

      expect(
        (fixture.nativeElement as HTMLElement).querySelector('.mini')!.getAttribute('aria-hidden'),
      ).toBe('true');
      fixture.destroy();
    });

    it('rides inside the split pane’s toolbar instead of taking a strip of its own', () => {
      const element = mount(entry({ faviconUrl: 'https://x/f.png' })).nativeElement as HTMLElement;
      expect(element.querySelector('.mini')).toBeNull();
      expect(element.querySelector('.bar .bar-title')!.textContent).toContain('Deep dive');
      expect(element.querySelector<HTMLImageElement>('.bar app-favicon img')!.src).toBe(
        'https://x/f.png',
      );
    });

    it('offers favourite and keep in the split pane’s toolbar', () => {
      const fixture = mount(entry({ isFavorite: true }));
      const element = fixture.nativeElement as HTMLElement;
      const favourite = element.querySelector<HTMLButtonElement>('.bar [aria-label="Favorite"]')!;
      const keep = element.querySelector<HTMLButtonElement>('.bar [aria-label="Keep"]')!;

      // The toolbar reports the entry's state, the way the article's action row does.
      expect(favourite.classList).toContain('on');
      expect(keep.classList).not.toContain('on');

      const favouriteEmits = jest.fn();
      const keepEmits = jest.fn();
      entryActions.favorite.mockImplementation(favouriteEmits);
      entryActions.keep.mockImplementation(keepEmits);
      favourite.click();
      keep.click();
      expect(favouriteEmits).toHaveBeenCalled();
      expect(keepEmits).toHaveBeenCalled();
    });

    it('draws the toolbar pair in the shared toggle look, filled only while on', () => {
      const fixture = mount(entry({ isFavorite: true, isKept: false }));
      const element = fixture.nativeElement as HTMLElement;
      const favourite = element.querySelector<HTMLButtonElement>('.bar [aria-label="Favorite"]')!;
      const keep = element.querySelector<HTMLButtonElement>('.bar [aria-label="Keep"]')!;
      const fillOf = (button: HTMLButtonElement) =>
        fixture.debugElement
          .queryAll(By.directive(IconComponent))
          .find((icon) => button.contains(icon.nativeElement))!
          .componentInstance.fill();

      expect(favourite.classList).toContain('flag-toggle');
      expect(keep.classList).toContain('flag-toggle');
      expect(favourite.getAttribute('aria-pressed')).toBe('true');
      expect(keep.getAttribute('aria-pressed')).toBe('false');
      expect(fillOf(favourite)).toBe(true);
      expect(fillOf(keep)).toBe(false);
    });

    it('offers favourite and keep in the full-screen toolbar too', () => {
      const fixture = TestBed.createComponent(ReaderViewComponent);
      fixture.componentRef.setInput('entry', entry());
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();

      const element = fixture.nativeElement as HTMLElement;
      // The nameplate still rides the .mini strip in full screen, not the bar.
      expect(element.querySelector('.bar .bar-title')).toBeNull();
      expect(element.querySelector('.bar [aria-label="Favorite"]')).not.toBeNull();
      expect(element.querySelector('.bar [aria-label="Keep"]')).not.toBeNull();
      fixture.destroy();
    });
  });

  describe('article refresh', () => {
    it('shows the refresh button in reader mode in both layouts', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const pane = mount(entry()).nativeElement as HTMLElement;
      expect(pane.querySelector('.bar [aria-label="Reload article"]')).not.toBeNull();

      const fixture = TestBed.createComponent(ReaderViewComponent);
      fixture.componentRef.setInput('entry', entry());
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();
      expect(
        (fixture.nativeElement as HTMLElement).querySelector('.bar [aria-label="Reload article"]'),
      ).not.toBeNull();
      fixture.destroy();
    });

    it('hides the refresh button once the reader switches to original', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const fixture = mount(entry());
      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('.bar [aria-label="Reload article"]')).not.toBeNull();

      fixture.componentInstance.toggleMode(); // reader -> original
      fixture.detectChanges();
      expect(element.querySelector('.bar [aria-label="Reload article"]')).toBeNull();
    });

    it('hides the refresh button when extraction failed (original fallback)', () => {
      // Default loadMock resolves failed, so the view falls back to original.
      const element = mount(entry()).nativeElement as HTMLElement;
      expect(element.querySelector('.bar [aria-label="Reload article"]')).toBeNull();
    });

    it('refetches past the cache and shows the loading state', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const subject = new Subject<ReaderContent>();
      reloadMock.mockReturnValue(subject.asObservable());
      const fixture = mount(entry());
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('.bar [aria-label="Reload article"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(reloadMock).toHaveBeenCalledWith(1);
      expect(element.querySelector('app-loading-overlay.shown')).not.toBeNull();

      subject.next(okContent({ contentHtml: '<p>FRESH</p>' }));
      subject.complete();
      fixture.detectChanges();
      expect(element.querySelector('app-loading-overlay.shown')).toBeNull();
      expect(element.querySelector('.content')!.innerHTML).toContain('FRESH');
    });

    it('refreshArticle does not reset the reader/original mode', () => {
      // The button is reader-only, but the method must leave the mode alone —
      // only a genuine entry change resets it.
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      reloadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const fixture = mount(entry());
      fixture.componentInstance.toggleMode(); // reader -> original
      expect(fixture.componentInstance.mode()).toBe('original');

      fixture.componentInstance.refreshArticle();
      fixture.detectChanges();
      expect(fixture.componentInstance.mode()).toBe('original');
    });
  });

  it('renders extracted reader content when extraction succeeds', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.content')!.innerHTML).toContain('READER');
  });

  it('falls back to feed content and shows a note when extraction fails', () => {
    loadMock.mockReturnValue(of<ReaderContent>(failedContent()));
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.content')!.innerHTML).toContain('Body');
    expect(element.querySelector('.reader-note')).not.toBeNull();
  });

  describe('reader fallback: retry and error detail', () => {
    it('retries extraction past the cache when the note link is clicked', () => {
      loadMock.mockReturnValue(of<ReaderContent>(failedContent()));
      const subject = new Subject<ReaderContent>();
      reloadMock.mockReturnValue(subject.asObservable());
      const fixture = mount(entry());
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('.note-link') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(reloadMock).toHaveBeenCalledWith(1);
      expect(element.querySelector('app-loading-overlay.shown')).not.toBeNull();

      subject.next(okContent({ contentHtml: '<p>FRESH</p>' }));
      subject.complete();
      fixture.detectChanges();
      expect(element.querySelector('.content')!.innerHTML).toContain('FRESH');
    });

    it('reveals the server-supplied cause behind a collapsed "show error" disclosure', () => {
      loadMock.mockReturnValue(
        of<ReaderContent>(failedContent({ reason: 'fetch', detail: 'HTTP 403 Forbidden' })),
      );
      const element = mount(entry()).nativeElement as HTMLElement;

      const details = element.querySelector('.reader-error') as HTMLDetailsElement;
      expect(details).not.toBeNull();
      expect(details.open).toBe(false);
      expect(details.querySelector('pre')!.textContent).toContain('HTTP 403 Forbidden');
    });

    it('falls back to the bare reason code when the server sent no cause', () => {
      loadMock.mockReturnValue(
        of<ReaderContent>(failedContent({ reason: 'unextractable', detail: null })),
      );
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.reader-error pre')!.textContent).toContain('unextractable');
    });

    it('reveals the complete HTTP message when the load fails at the transport', () => {
      loadMock.mockReturnValue(
        throwError(
          () =>
            new HttpErrorResponse({
              status: 502,
              statusText: 'Bad Gateway',
              url: 'https://host.test/api/entries/1/reader',
            }),
        ),
      );
      const element = mount(entry()).nativeElement as HTMLElement;

      const detail = element.querySelector('.reader-error pre')!.textContent!;
      expect(detail).toContain('502');
      expect(detail).toContain('Bad Gateway');
      expect(detail).toContain('https://host.test/api/entries/1/reader');
    });
  });

  it('says above the body that this is the free preview of a paywalled article', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(okContent({ paywalled: true, url: 'https://pub.test/a' })),
    );
    const element = mount(entry()).nativeElement as HTMLElement;
    const note = element.querySelector('.paywall-note');
    const content = element.querySelector('.content')!;
    expect(note).not.toBeNull();
    expect(note!.compareDocumentPosition(content) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(note!.querySelector('a')!.getAttribute('href')).toBe('https://pub.test/a');
  });

  it('shows no paywall note for a freely readable article', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent({ paywalled: false })));
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.paywall-note')).toBeNull();
  });

  it('drops the paywall note in the original view, which shows the feed body', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent({ paywalled: true })));
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.paywall-note')).not.toBeNull();

    (element.querySelector('.mode') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('.paywall-note')).toBeNull();
  });

  it('toggles between reader and original', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.content')!.innerHTML).toContain('READER');

    (element.querySelector('.mode') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('.content')!.innerHTML).toContain('Body');

    (element.querySelector('.mode') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('.content')!.innerHTML).toContain('READER');
  });

  it('shows a loading indicator while extraction is pending', () => {
    loadMock.mockReturnValue(new Subject<ReaderContent>());
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('app-loading-overlay.shown')).not.toBeNull();
    expect(element.querySelector('.content')).toBeNull();
    // The overlay is decorative, so the article carries the busy state instead.
    expect(element.querySelector('article')!.getAttribute('aria-busy')).toBe('true');
  });

  it('does not reload or reset the toggle when the same entry changes by reference', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    expect(loadMock).toHaveBeenCalledTimes(1);

    // Switch to Original, then simulate an optimistic flag update: a NEW entry
    // object with the SAME id (what entries.store produces on favorite/keep/read).
    (element.querySelector('.mode') as HTMLButtonElement).click();
    fixture.detectChanges();
    fixture.componentRef.setInput('entry', entry({ isFavorite: true }));
    fixture.detectChanges();

    expect(loadMock).toHaveBeenCalledTimes(1); // no redundant re-fetch
    expect(element.querySelector('.content')!.innerHTML).toContain('Body'); // still Original
  });

  it('reloads when a different entry (new id) is shown', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const fixture = mount(entry({ id: 1 }));
    expect(loadMock).toHaveBeenCalledTimes(1);

    fixture.componentRef.setInput('entry', entry({ id: 2 }));
    fixture.detectChanges();

    expect(loadMock).toHaveBeenCalledTimes(2);
    expect(loadMock).toHaveBeenLastCalledWith(2);
  });

  const hero = (fixture: { nativeElement: unknown }) =>
    (fixture.nativeElement as HTMLElement).querySelector('.lead-image') as HTMLImageElement | null;

  it('renders no separate hero in reader mode; the lead rides in contentHtml', () => {
    // The backend now restores the lead picture into the extracted body itself
    // (#681), so reader mode shows it as part of the article, not a hero element.
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({
          contentHtml: '<figure><img src="https://img.test/hero.jpg"></figure><p>Body</p>',
        }),
      ),
    );
    const fixture = mount(entry());

    expect(hero(fixture)).toBeNull();
    const content = (fixture.nativeElement as HTMLElement).querySelector('.content');
    expect(content!.innerHTML).toContain('https://img.test/hero.jpg');
  });

  it('shows the original hero only after toggling to the original view', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({ originalHero: { url: 'https://img.test/feed.jpg', width: 800, height: 450 } }),
      ),
    );
    const fixture = mount(entry());
    expect(hero(fixture)).toBeNull();

    TestBed.inject(ReaderModeService).toggle();
    fixture.detectChanges();

    expect(hero(fixture)!.getAttribute('src')).toBe('https://img.test/feed.jpg');
    expect(hero(fixture)!.getAttribute('width')).toBe('800');
    expect(hero(fixture)!.getAttribute('height')).toBe('450');
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

  it('hides the original hero whose image fails to load', async () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        failedContent({
          originalHero: { url: 'https://img.test/gone.jpg', width: null, height: null },
        }),
      ),
    );
    const fixture = mount(entry());

    hero(fixture)!.dispatchEvent(new Event('error'));
    await fixture.whenStable();
    fixture.detectChanges();

    expect(hero(fixture)).toBeNull();
  });

  it('hands a failed article-body image to the proxy', async () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({
          contentHtml: '<p>Lead.</p><img src="https://www.oxmoxhh.de/cover.png" alt="">',
        }),
      ),
    );
    const fixture = mount(entry());
    await fixture.whenStable();
    const img = fixture.nativeElement.querySelector('.content img') as HTMLImageElement;

    img.dispatchEvent(new Event('error'));

    expect(imageProxy.recover).toHaveBeenCalledWith(img);
  });

  it('renders no hero in reader mode when the backend resolved none', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));

    expect(hero(mount(entry()))).toBeNull();
  });

  describe('entry with no article URL (#1140)', () => {
    it('does not ask for an extraction when the entry has no article URL', () => {
      const element = mount(
        entry({ url: null, discussionUrl: 'https://www.reddit.com/r/x/comments/1/t/' }),
      ).nativeElement as HTMLElement;

      expect(loadMock).not.toHaveBeenCalled();
      expect(element.querySelector('.reader-fallback')).toBeNull();
      expect(element.querySelector('.mode')).toBeNull();
    });

    it('treats an empty article URL as no article URL', () => {
      const element = mount(entry({ url: '' })).nativeElement as HTMLElement;

      expect(loadMock).not.toHaveBeenCalled();
      expect(element.querySelector('.mode')).toBeNull();
    });
  });

  describe('discussion link (#1140)', () => {
    it('links the discussion page when there is one', () => {
      const element = mount(entry({ discussionUrl: 'https://news.ycombinator.com/item?id=1' }))
        .nativeElement as HTMLElement;

      const link = element.querySelector('a.discussion-link');
      expect(link?.getAttribute('href')).toBe('https://news.ycombinator.com/item?id=1');
    });

    it('shows no discussion link when the entry has none', () => {
      const element = mount(entry({ discussionUrl: null })).nativeElement as HTMLElement;

      expect(element.querySelector('a.discussion-link')).toBeNull();
    });
  });

  describe('comments section (#1140)', () => {
    let commentsState: WritableSignal<CommentsState>;

    beforeEach(() => {
      commentsState = signal<CommentsState>({ status: 'idle' });
      stubComments(commentsState);
    });

    function commentsSection(fixture: { nativeElement: HTMLElement }): Element | null {
      return fixture.nativeElement.querySelector('article app-entry-comments');
    }

    const loadedComments: CommentsState = {
      status: 'ok',
      comments: [
        {
          author: 'u/first',
          authorUrl: null,
          url: null,
          publishedAt: null,
          byEntryAuthor: false,
          html: '<p>First comment</p>',
        },
      ],
      loadedAt: 0,
    };

    it('follows the article when the entry has a comments feed', () => {
      expect(commentsSection(mount(entry({ comments: 'auto' })))).not.toBeNull();
    });

    it('is absent without a comments feed', () => {
      expect(commentsSection(mount(entry({ comments: null })))).toBeNull();
    });

    it('waits for the article to finish loading', () => {
      loadMock.mockReturnValue(new Subject<ReaderContent>());

      expect(commentsSection(mount(entry({ comments: 'manual' })))).toBeNull();
    });

    it('falls under the reading focus with the article body (#1150)', async () => {
      commentsState.set(loadedComments);
      const fixture = mount(entry({ comments: 'manual' }));
      await Promise.resolve();
      fixture.detectChanges();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      const list = commentsSection(fixture)!.querySelector<HTMLElement>('.list')!;
      expect(list.style.opacity).not.toBe('');
    });

    it('re-seats the reading focus when the comments arrive late (#1150)', async () => {
      commentsState.set({ status: 'loading' });
      const fixture = mount(entry({ comments: 'manual' }));
      await Promise.resolve();
      fixture.detectChanges();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      commentsState.set(loadedComments);
      fixture.detectChanges();
      const host = commentsSection(fixture)!;
      MockResizeObserver.instances.find((observer) => observer.targets.has(host))!.fire();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      expect(host.querySelector<HTMLElement>('.list')!.style.opacity).not.toBe('');
    });
  });

  it('falls back to the feed summary when contentHtml is null on failure', () => {
    loadMock.mockReturnValue(of<ReaderContent>(failedContent()));
    const element = mount(entryWithBody(null, { summary: 'Just a summary' }))
      .nativeElement as HTMLElement;
    expect(element.querySelector('.content')!.innerHTML).toContain('Just a summary');
  });

  describe('return-to-list gestures (full-screen)', () => {
    const touch = (x: number, y: number) =>
      ({
        touches: [{ clientX: x, clientY: y }],
        preventDefault() {
          /* test stub */
        },
      }) as unknown as TouchEvent;

    const audioControl = document.createElement('audio');
    const touchOnControl = (x: number, y: number) =>
      ({
        target: audioControl,
        touches: [{ clientX: x, clientY: y }],
        preventDefault() {
          /* test stub */
        },
      }) as unknown as TouchEvent;

    function fullscreen() {
      const fixture = mount(entry());
      fixture.componentRef.setInput('fullscreen', true);
      fixture.detectChanges();
      return fixture;
    }

    it('moves nothing at rest, the layer on a swipe and the article on a pull', () => {
      const fixture = fullscreen();
      const element = fixture.nativeElement as HTMLElement;
      const frame = element.querySelector('.frame') as HTMLElement;
      const reader = element.querySelector('.reader') as HTMLElement;
      expect([frame.style.transform, reader.style.transform]).toEqual(['none', 'none']);

      gestures(fixture).onTouchStart(touch(0, 0));
      gestures(fixture).onTouchMove(touch(40, 4));
      fixture.detectChanges();
      expect(frame.style.transform).toBe('translate3d(40px, 0, 0)');
      expect(reader.style.transform).toBe('none');
      gestures(fixture).onTouchEnd();

      gestures(fixture).onTouchStart(touch(5, 300));
      gestures(fixture).onTouchMove(touch(7, 280));
      fixture.detectChanges();
      expect(frame.style.transform).toBe('none');
      expect(reader.style.transform).toMatch(/^translate3d\(0, -\d/);
      fixture.destroy();
    });

    it('returns to the list on a decisive rightward swipe', () => {
      const fixture = fullscreen();
      const component = gestures(fixture);
      component.onTouchStart(touch(0, 0));
      component.onTouchMove(touch(130, 6));
      component.onTouchEnd();
      expect(component.leaving()).toBe(true);
      fixture.destroy();
    });

    it('slides the article out to the right, then returns, on a back-button click', fakeAsync(() => {
      const fixture = fullscreen();
      const element = fixture.nativeElement as HTMLElement;
      const close = jest.fn();
      fixture.componentInstance.close.subscribe(close);
      (element.querySelector('.bar .close') as HTMLButtonElement).click();
      fixture.detectChanges();
      // Committed to leaving and slid fully off to the right (same as a swipe).
      expect(gestures(fixture).leaving()).toBe(true);
      expect((element.querySelector('.frame') as HTMLElement).style.transform).toContain(
        `${window.innerWidth}px`,
      );
      // close only fires once the slide-out animation has played.
      expect(close).not.toHaveBeenCalled();
      tick(220);
      expect(close).toHaveBeenCalledTimes(1);
      fixture.destroy();
    }));

    it('snaps back (does not return) on a short swipe', () => {
      const component = gestures(fullscreen());
      component.onTouchStart(touch(0, 0));
      component.onTouchMove(touch(30, 4));
      component.onTouchEnd();
      expect(component.leaving()).toBe(false);
    });

    it('yields to a media control: a drag from the audio scrubber does not return to the list', () => {
      const fixture = fullscreen();
      const component = gestures(fixture);
      component.onTouchStart(touchOnControl(0, 0));
      component.onTouchMove(touchOnControl(130, 6)); // a decisive rightward drag on the scrubber
      component.onTouchEnd();
      expect(component.leaving()).toBe(false);
      fixture.destroy();
    });

    it('still returns to the list on a real swipe right after a suppressed one', () => {
      const fixture = fullscreen();
      const component = gestures(fixture);
      component.onTouchStart(touchOnControl(0, 0)); // suppressed: began on the scrubber
      component.onTouchMove(touchOnControl(130, 6));
      component.onTouchEnd();
      component.onTouchStart(touch(0, 0)); // a real back-swipe on the article surface
      component.onTouchMove(touch(130, 6));
      component.onTouchEnd();
      expect(component.leaving()).toBe(true);
      fixture.destroy();
    });

    it('returns to the list on a pull past the article end', () => {
      const fixture = fullscreen();
      const component = gestures(fixture);
      // jsdom has no layout, so the scroller reads as already at the bottom.
      component.onTouchStart(touch(5, 300));
      component.onTouchMove(touch(7, 0)); // strong upward pull → rubber-banded past threshold
      component.onTouchEnd();
      expect(component.leaving()).toBe(true);
      fixture.destroy();
    });

    it('ignores swipes while the in-pane toolbar is shown (split-pane)', () => {
      const component = gestures(mount(entry())); // showToolbar defaults to true
      component.onTouchStart(touch(0, 0));
      component.onTouchMove(touch(200, 0));
      component.onTouchEnd();
      expect(component.leaving()).toBe(false);
    });
  });

  describe('audio attachment', () => {
    it('offers a listen control for an audio enclosure and plays it', () => {
      const fixture = mount(
        entry({
          attachments: [
            {
              url: 'https://x.test/ep.mp3',
              mimeType: 'audio/mpeg',
              title: 'Ep 1',
              durationInSeconds: 120,
            },
          ],
        }),
      );
      const play = jest.spyOn(TestBed.inject(AudioPlayerService), 'play').mockImplementation(() => {
        /* Do not touch the real audio element in the render test. */
      });

      const button = fixture.debugElement.query(By.css('.listen'));
      button.nativeElement.click();

      expect(play).toHaveBeenCalledWith(
        expect.objectContaining({ url: 'https://x.test/ep.mp3', title: 'Ep 1' }),
      );
    });

    it('shows no listen control when the entry has no audio enclosure', () => {
      const fixture = mount(
        entry({ attachments: [{ url: 'https://x.test/clip.mp4', mimeType: 'video/mp4' }] }),
      );

      expect(fixture.debugElement.query(By.css('.listen'))).toBeNull();
    });
  });

  describe('feed-declared categories', () => {
    it('renders the joined categories as a comma-separated footer row', () => {
      const element = mount(entry({ categories: ['Politics', 'World'] }))
        .nativeElement as HTMLElement;

      expect(element.querySelector('.categories')?.textContent?.trim()).toBe('Politics, World');
    });

    it('omits the categories row when there are none', () => {
      const element = mount(entry({ categories: [] })).nativeElement as HTMLElement;

      expect(element.querySelector('.categories')).toBeNull();
    });
  });

  describe('feed body from the store (#1100)', () => {
    it('shows the summary at once, before the body arrives, with no spinner over it', () => {
      fakeBody.setLoading(1);
      const element = mount(entry({ summary: 'The summary text' })).nativeElement as HTMLElement;

      expect(element.querySelector('.content')!.textContent).toContain('The summary text');
      expect(element.querySelector('app-loading-overlay.shown')).toBeNull();
    });

    it('replaces the summary with the body once it arrives', () => {
      fakeBody.setLoading(1);
      const fixture = mount(entry({ summary: 'The summary text' }));
      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('.content')!.textContent).toContain('The summary text');

      fakeBody.seed(1, '<p>The full body</p>');
      fixture.detectChanges();

      expect(element.querySelector('.content')!.innerHTML).toContain('The full body');
      expect(element.querySelector('.content')!.textContent).not.toContain('The summary text');
    });

    it('keeps the summary and shows an inline error when the body fails to load', () => {
      fakeBody.setError(1);
      const element = mount(entry({ summary: 'The summary text' })).nativeElement as HTMLElement;

      expect(element.querySelector('.content')!.textContent).toContain('The summary text');
      expect(element.querySelector('.body-error')).not.toBeNull();
    });

    it('retries the failed body fetch from the inline error action', () => {
      fakeBody.setError(1);
      const fixture = mount(entry());
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('.body-error .action') as HTMLButtonElement).click();

      expect(fakeBody.retriedIds).toEqual([1]);
    });

    it('suppresses the inline body error once reader mode has extracted content', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      fakeBody.setError(1);

      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.body-error')).toBeNull();
    });
  });
});
