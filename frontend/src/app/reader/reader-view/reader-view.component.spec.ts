import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { of, Subject } from 'rxjs';
import { ReaderViewComponent } from './reader-view.component';
import { ReaderContentService } from '../reader-content.service';
import { entryScrollKey } from '../list-scroll-memory';
import { EntryDto, ReaderContent } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Deep dive',
  url: 'https://x/1',
  author: 'Ada',
  summary: null,
  contentHtml: '<p>Body</p><a href="https://ext.test/z">link</a>',
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'Ars',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

let loadMock: jest.Mock;

function mount(e: EntryDto | null, hasPrev = true, hasNext = true) {
  const f = TestBed.createComponent(ReaderViewComponent);
  f.componentRef.setInput('entry', e);
  f.componentRef.setInput('hasPrev', hasPrev);
  f.componentRef.setInput('hasNext', hasNext);
  f.detectChanges();
  return f;
}

const okContent = (over: Partial<ReaderContent> = {}): ReaderContent => ({
  status: 'ok',
  contentHtml: '<p>READER</p>',
  url: '',
  title: '',
  byline: null,
  siteName: null,
  excerpt: null,
  leadImage: null,
  extractedAt: '',
  ...over,
});

describe('ReaderViewComponent', () => {
  beforeEach(() => {
    // Default: extraction fails so the existing presentational tests keep
    // asserting against the feed's own content. Reader-specific tests override.
    loadMock = jest.fn(() => of<ReaderContent>({ status: 'failed', reason: 'fetch', url: null }));
    TestBed.configureTestingModule({
      imports: [ReaderViewComponent, provideTranslocoTesting()],
      providers: [{ provide: ReaderContentService, useValue: { load: loadMock } }],
    });
  });

  it('renders title, meta, content and decorates external links', async () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('Deep dive');
    expect(el.querySelector('.meta')!.textContent).toContain('Ars');
    expect(el.querySelector('.content')!.textContent).toContain('Body');
    await Promise.resolve(); // link decoration runs in a microtask
    const a = el.querySelector('.content a') as HTMLAnchorElement;
    expect(a.target).toBe('_blank');
    expect(a.rel).toContain('noopener');
  });

  it('leaves in-page fragment anchors undecorated', async () => {
    const el = mount(
      entry({ contentHtml: '<a href="#footnote">jump</a><a href="https://ext.test/z">ext</a>' }),
    ).nativeElement as HTMLElement;
    await Promise.resolve(); // link decoration runs in a microtask
    const anchors = el.querySelectorAll('.content a');
    expect((anchors[0] as HTMLAnchorElement).target).toBe(''); // fragment link untouched
    expect((anchors[1] as HTMLAnchorElement).target).toBe('_blank'); // external decorated
  });

  describe('table of contents', () => {
    const threeHeadings = '<h2>Alpha</h2><p>a</p><h2>Beta</h2><p>b</p><h3>Gamma</h3>';

    it('shows a table of contents, collapsed by default, for articles with several headings', async () => {
      const f = mount(entry({ contentHtml: threeHeadings }));
      await Promise.resolve(); // content-processing microtask builds the TOC
      f.detectChanges();
      const el = f.nativeElement as HTMLElement;
      const toggle = el.querySelector('.toc-toggle') as HTMLButtonElement;
      expect(toggle).not.toBeNull();
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(el.querySelectorAll('.toc-list li').length).toBe(0); // collapsed

      toggle.click();
      f.detectChanges();
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
      const items = [...el.querySelectorAll('.toc-list button')].map((b) => b.textContent?.trim());
      expect(items).toEqual(['Alpha', 'Beta', 'Gamma']);
    });

    it('gives the headings unique ids so the TOC can jump to them', async () => {
      const f = mount(entry({ contentHtml: '<h2>Same</h2><h2>Same</h2><h2>Same</h2>' }));
      await Promise.resolve();
      f.detectChanges();
      const ids = [...(f.nativeElement as HTMLElement).querySelectorAll('.content h2')].map(
        (h) => (h as HTMLElement).id,
      );
      expect(ids.every((id) => id.length > 0)).toBe(true);
      expect(new Set(ids).size).toBe(3); // deduped
    });

    it('omits the TOC for short articles', async () => {
      const f = mount(entry({ contentHtml: '<h2>Only one</h2><p>x</p>' }));
      await Promise.resolve();
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('.toc')).toBeNull();
    });
  });

  describe('back-to-top button', () => {
    function scrollHostTo(host: HTMLElement, top: number): void {
      Object.defineProperty(host, 'scrollTop', { configurable: true, value: top });
      host.dispatchEvent(new Event('scroll'));
    }

    it('appears only after scrolling down and jumps back to the top on click', () => {
      const f = mount(entry());
      const host = f.nativeElement as HTMLElement;
      expect(host.querySelector('app-to-top-button')).toBeNull(); // hidden at the top

      scrollHostTo(host, 900);
      f.detectChanges();
      const btn = host.querySelector('app-to-top-button button') as HTMLButtonElement;
      expect(btn).not.toBeNull();

      const scrollTo = jest.fn();
      host.scrollTo = scrollTo as unknown as typeof host.scrollTo;
      btn.click();
      expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }));
    });

    it('hides again when scrolled back near the top', () => {
      const f = mount(entry());
      const host = f.nativeElement as HTMLElement;
      scrollHostTo(host, 900);
      f.detectChanges();
      expect(host.querySelector('app-to-top-button')).not.toBeNull();

      scrollHostTo(host, 20);
      f.detectChanges();
      expect(host.querySelector('app-to-top-button')).toBeNull();
    });

    // #98: the button unmounts once showToTop flips false, which would otherwise
    // drop keyboard focus to <body> and strand a keyboard/screen-reader user.
    it('moves focus to the article title instead of dropping it to the body', () => {
      const f = mount(entry());
      const host = f.nativeElement as HTMLElement;
      scrollHostTo(host, 900);
      f.detectChanges();
      host.scrollTo = jest.fn() as unknown as typeof host.scrollTo;

      (host.querySelector('app-to-top-button button') as HTMLButtonElement).click();

      expect(document.activeElement).toBe(host.querySelector('h1.title'));
    });
  });

  // #101: the restore has to fire on every path that ends with the article
  // rendered — including extraction failure, where the rendered HTML never
  // changes value (mode flips reader -> original but the feed's own content is
  // shown either way), so the content signal alone can never trigger it.
  describe('article scroll restore', () => {
    // jsdom has no layout, so a real scrollTop write is a no-op and always reads
    // back 0. Record the writes instead — that is what the restore does.
    function trackScrollTop(host: HTMLElement): { top: number } {
      const state = { top: 0 };
      Object.defineProperty(host, 'scrollTop', {
        configurable: true,
        get: () => state.top,
        set: (v: number) => {
          state.top = v;
        },
      });
      return state;
    }

    /** Mount entry 1 with a remembered offset and extraction still in flight. */
    function mountRemembering(top: number) {
      const load = new Subject<ReaderContent>();
      loadMock.mockReturnValue(load);
      sessionStorage.setItem(entryScrollKey(1), String(top));
      const f = TestBed.createComponent(ReaderViewComponent);
      const scroll = trackScrollTop(f.nativeElement as HTMLElement);
      f.componentRef.setInput('entry', entry({ id: 1 }));
      f.detectChanges();
      return { f, scroll, load };
    }

    /** Let the content-processing microtask and any follow-up effect settle. */
    async function settle(f: { detectChanges(): void }) {
      await Promise.resolve();
      f.detectChanges();
      await Promise.resolve();
    }

    afterEach(() => sessionStorage.clear());

    it('restores the remembered offset when extraction fails', async () => {
      const { f, scroll, load } = mountRemembering(900);
      load.next({ status: 'failed', reason: 'fetch', url: null });
      await settle(f);
      expect(scroll.top).toBe(900);
      f.destroy();
    });

    it('restores the remembered offset when extraction succeeds', async () => {
      const { f, scroll, load } = mountRemembering(900);
      load.next(okContent());
      await settle(f);
      expect(scroll.top).toBe(900);
      f.destroy();
    });

    it('leaves an article with no remembered offset at the top', async () => {
      const { f, scroll, load } = mountRemembering(0);
      load.next({ status: 'failed', reason: 'fetch', url: null });
      await settle(f);
      expect(scroll.top).toBe(0);
      f.destroy();
    });
  });

  // #107: the reading focus only ever fully highlights the block at the viewport
  // centre, and the article used to stop scrolling with its last paragraph at the
  // bottom edge — the one block that could never be brought into focus.
  describe('tail space below a long article', () => {
    /** Pin the pane's height and where the article's own content box ends. */
    function stubGeometry(f: ReturnType<typeof mount>, contentBottom: number, viewport: number) {
      const host = f.nativeElement as HTMLElement;
      Object.defineProperty(host, 'clientHeight', { configurable: true, value: viewport });
      host.getBoundingClientRect = () => ({ top: 0, bottom: viewport }) as DOMRect;
      const content = host.querySelector('.content') as HTMLElement;
      content.getBoundingClientRect = () => ({ top: 0, bottom: contentBottom }) as DOMRect;
      // A resize is one of the moments the pane re-measures itself.
      window.dispatchEvent(new Event('resize'));
      f.detectChanges();
      return host;
    }

    it('adds it when the article is taller than the pane', () => {
      const f = mount(entry());
      const host = stubGeometry(f, 2400, 800);
      expect(host.querySelector('.reader')!.classList).toContain('with-tail');
    });

    it('withholds it from an article that fits, which would be dead scroll', () => {
      const f = mount(entry());
      const host = stubGeometry(f, 400, 800);
      expect(host.querySelector('.reader')!.classList).not.toContain('with-tail');
    });
  });

  it('emits favorite/keep/read/prev/next/close', () => {
    const f = mount(entry());
    const c = { favorite: 0, keep: 0, read: 0, prev: 0, next: 0, close: 0 };
    (Object.keys(c) as (keyof typeof c)[]).forEach((k) =>
      f.componentInstance[k].subscribe(() => c[k]++),
    );
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Favorite"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Keep"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Toggle read"]') as HTMLButtonElement).click();
    (el.querySelector('.prev') as HTMLButtonElement).click();
    (el.querySelector('.next') as HTMLButtonElement).click();
    (el.querySelector('.close') as HTMLButtonElement).click();
    expect(c).toEqual({ favorite: 1, keep: 1, read: 1, prev: 1, next: 1, close: 1 });
  });

  it('in full-screen (no toolbar) hides the bar and shows a content back button', () => {
    const f = TestBed.createComponent(ReaderViewComponent);
    f.componentRef.setInput('entry', entry());
    f.componentRef.setInput('showToolbar', false);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.bar')).toBeNull();
    const close = jest.fn();
    f.componentInstance.close.subscribe(close);
    const back = el.querySelector('.title-row .back') as HTMLButtonElement;
    expect(back).not.toBeNull();
    back.click();
    // The back button plays the slide-out (like a back-swipe) rather than
    // cutting straight to the list, so close is deferred until it finishes.
    expect(close).not.toHaveBeenCalled();
    expect(f.componentInstance.leaving()).toBe(true);
    f.destroy();
  });

  it('keeps the back button in the toolbar (not the content) when the toolbar shows', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.title-row .back')).toBeNull();
    expect(el.querySelector('.bar .close')).not.toBeNull();
  });

  // The panel reserves the floating app bar's height at its top, and how much it
  // reserves depends on whether its own toolbar is there to hang beneath the bar
  // (#97). jsdom cannot see the resulting layout, so pin the flag the stylesheet
  // keys off instead — silently losing it would drop the article's first lines
  // behind the app bar.
  it('marks the panel as carrying its own toolbar only when it renders one', () => {
    const withBar = mount(entry()).nativeElement as HTMLElement;
    expect(withBar.querySelector('.reader')!.classList).toContain('with-bar');

    const f = TestBed.createComponent(ReaderViewComponent);
    f.componentRef.setInput('entry', entry());
    f.componentRef.setInput('showToolbar', false);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.reader')!.classList).not.toContain('with-bar');
    f.destroy();
  });

  it('disables prev/next at the ends', () => {
    const el = mount(entry(), false, false).nativeElement as HTMLElement;
    expect((el.querySelector('.prev') as HTMLButtonElement).disabled).toBe(true);
    expect((el.querySelector('.next') as HTMLButtonElement).disabled).toBe(true);
  });

  it('renders extracted reader content when extraction succeeds', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.content')!.innerHTML).toContain('READER');
  });

  it('falls back to feed content and shows a note when extraction fails', () => {
    loadMock.mockReturnValue(of<ReaderContent>({ status: 'failed', reason: 'fetch', url: null }));
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.content')!.innerHTML).toContain('Body');
    expect(el.querySelector('.reader-note')).not.toBeNull();
  });

  it('toggles between reader and original', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const f = mount(entry());
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.content')!.innerHTML).toContain('READER');

    (el.querySelector('.mode') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('.content')!.innerHTML).toContain('Body');

    (el.querySelector('.mode') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('.content')!.innerHTML).toContain('READER');
  });

  it('shows a loading indicator while extraction is pending', () => {
    loadMock.mockReturnValue(new Subject<ReaderContent>());
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.loading')).not.toBeNull();
    expect(el.querySelector('.content')).toBeNull();
  });

  it('does not reload or reset the toggle when the same entry changes by reference', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const f = mount(entry());
    const el = f.nativeElement as HTMLElement;
    expect(loadMock).toHaveBeenCalledTimes(1);

    // Switch to Original, then simulate an optimistic flag update: a NEW entry
    // object with the SAME id (what entries.store produces on favorite/keep/read).
    (el.querySelector('.mode') as HTMLButtonElement).click();
    f.detectChanges();
    f.componentRef.setInput('entry', entry({ isFavorite: true }));
    f.detectChanges();

    expect(loadMock).toHaveBeenCalledTimes(1); // no redundant re-fetch
    expect(el.querySelector('.content')!.innerHTML).toContain('Body'); // still Original
  });

  it('reloads when a different entry (new id) is shown', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const f = mount(entry({ id: 1 }));
    expect(loadMock).toHaveBeenCalledTimes(1);

    f.componentRef.setInput('entry', entry({ id: 2 }));
    f.detectChanges();

    expect(loadMock).toHaveBeenCalledTimes(2);
    expect(loadMock).toHaveBeenLastCalledWith(2);
  });

  it('renders the lead image as a hero when the extracted body has none', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(okContent({ leadImage: 'https://img.test/hero.jpg' })),
    );
    const img = (mount(entry()).nativeElement as HTMLElement).querySelector(
      '.lead-image',
    ) as HTMLImageElement | null;
    expect(img).not.toBeNull();
    expect(img!.getAttribute('src')).toBe('https://img.test/hero.jpg');
  });

  it('falls back to the feed summary when contentHtml is null on failure', () => {
    loadMock.mockReturnValue(of<ReaderContent>({ status: 'failed', reason: 'fetch', url: null }));
    const el = mount(entry({ contentHtml: null, summary: 'Just a summary' }))
      .nativeElement as HTMLElement;
    expect(el.querySelector('.content')!.innerHTML).toContain('Just a summary');
  });

  describe('return-to-list gestures (full-screen)', () => {
    const touch = (x: number, y: number) =>
      ({
        touches: [{ clientX: x, clientY: y }],
        preventDefault() {
          /* test stub */
        },
      }) as unknown as TouchEvent;

    function fullscreen() {
      const f = mount(entry());
      f.componentRef.setInput('showToolbar', false);
      f.detectChanges();
      return f;
    }

    it('returns to the list on a decisive rightward swipe', () => {
      const f = fullscreen();
      const c = f.componentInstance;
      c.onTouchStart(touch(0, 0));
      c.onTouchMove(touch(130, 6));
      c.onTouchEnd();
      expect(c.leaving()).toBe(true);
      f.destroy();
    });

    it('slides the article out to the right, then returns, on a back-button click', fakeAsync(() => {
      const f = fullscreen();
      const el = f.nativeElement as HTMLElement;
      const close = jest.fn();
      f.componentInstance.close.subscribe(close);
      (el.querySelector('.title-row .back') as HTMLButtonElement).click();
      f.detectChanges();
      // Committed to leaving and slid fully off to the right (same as a swipe).
      expect(f.componentInstance.leaving()).toBe(true);
      expect((el.querySelector('.reader') as HTMLElement).style.transform).toContain(
        `${window.innerWidth}px`,
      );
      // close only fires once the slide-out animation has played.
      expect(close).not.toHaveBeenCalled();
      tick(220);
      expect(close).toHaveBeenCalledTimes(1);
      f.destroy();
    }));

    it('snaps back (does not return) on a short swipe', () => {
      const c = fullscreen().componentInstance;
      c.onTouchStart(touch(0, 0));
      c.onTouchMove(touch(30, 4));
      c.onTouchEnd();
      expect(c.leaving()).toBe(false);
    });

    it('returns to the list on a pull past the article end', () => {
      const f = fullscreen();
      const c = f.componentInstance;
      // jsdom has no layout, so the scroller reads as already at the bottom.
      c.onTouchStart(touch(5, 300));
      c.onTouchMove(touch(7, 0)); // strong upward pull → rubber-banded past threshold
      c.onTouchEnd();
      expect(c.leaving()).toBe(true);
      f.destroy();
    });

    it('ignores swipes while the in-pane toolbar is shown (split-pane)', () => {
      const c = mount(entry()).componentInstance; // showToolbar defaults to true
      c.onTouchStart(touch(0, 0));
      c.onTouchMove(touch(200, 0));
      c.onTouchEnd();
      expect(c.leaving()).toBe(false);
    });
  });
});
