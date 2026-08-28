import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { of, Subject } from 'rxjs';
import { ReaderViewComponent } from './reader-view.component';
import { ReaderContentService } from '../reader-content.service';
import { entryScrollKey } from '../list-scroll-memory';
import { EntryDto, ReaderArticle, ReaderContent, ReaderFailure } from '../models';
import { ReaderModeService } from '../reader-mode.service';
import { ReadingFocusService } from '../../core/reading-focus.service';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Deep dive',
  url: 'https://x/1',
  author: 'Ada',
  summary: null,
  contentHtml: '<p>Body</p><a href="https://ext.test/z">link</a>',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'Ars',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

let loadMock: jest.Mock;
let reloadMock: jest.Mock;

function mount(e: EntryDto | null) {
  const f = TestBed.createComponent(ReaderViewComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

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

describe('ReaderViewComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    // Default: extraction fails so the existing presentational tests keep
    // asserting against the feed's own content. Reader-specific tests override.
    loadMock = jest.fn(() => of<ReaderContent>(failedContent()));
    reloadMock = jest.fn(() => of<ReaderContent>(okContent()));
    TestBed.configureTestingModule({
      imports: [ReaderViewComponent, provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        { provide: ReaderContentService, useValue: { load: loadMock, reload: reloadMock } },
      ],
    });
  });

  describe('reading focus setting', () => {
    it('clears dimming from the open article when disabled', async () => {
      const f = mount(entry({ contentHtml: '<p>First</p><p>Second</p>' }));
      await Promise.resolve();
      f.detectChanges();
      const blocks = Array.from(
        (f.nativeElement as HTMLElement).querySelectorAll<HTMLElement>('.content > *'),
      );
      for (const block of blocks) block.style.opacity = '0.28';

      TestBed.inject(ReadingFocusService).setEnabled(false);
      f.detectChanges();

      expect(blocks.map((block) => block.style.opacity)).toEqual(['', '']);
    });

    it('restores dimming in the open article when enabled again', async () => {
      const readingFocus = TestBed.inject(ReadingFocusService);
      readingFocus.setEnabled(false);
      const f = mount(entry({ contentHtml: '<p>First</p><p>Second</p>' }));
      await Promise.resolve();
      f.detectChanges();
      const blocks = Array.from(
        (f.nativeElement as HTMLElement).querySelectorAll<HTMLElement>('.content > *'),
      );
      expect(blocks.map((block) => block.style.opacity)).toEqual(['', '']);

      readingFocus.setEnabled(true);
      f.detectChanges();
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

      expect(blocks.map((block) => block.style.opacity)).not.toContain('');
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

  describe('reading time', () => {
    const longBody = `<p>${Array.from({ length: 660 }, (_, i) => `w${i}`).join(' ')}</p>`;

    it('shows the estimate for a long article', () => {
      const f = mount(entry({ contentHtml: longBody }));

      expect(f.nativeElement.querySelector('.meta')?.textContent).toContain('≈ 3 min');
    });

    it('hides the estimate for a short article', () => {
      const f = mount(entry({ contentHtml: '<p>Tiny.</p>' }));

      expect(f.nativeElement.querySelector('.meta')?.textContent).not.toContain('≈');
    });
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
      load.next(failedContent());
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
      load.next(failedContent());
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

  it('emits favorite/keep/read/close', () => {
    const f = mount(entry());
    const c = { favorite: 0, keep: 0, read: 0, close: 0 };
    (Object.keys(c) as (keyof typeof c)[]).forEach((k) =>
      f.componentInstance[k].subscribe(() => c[k]++),
    );
    const el = f.nativeElement as HTMLElement;
    // Scoped to the article's own row: the split pane's toolbar carries a
    // second favourite/keep pair, and it comes first in the DOM.
    (el.querySelector('.actions [aria-label="Favorite"]') as HTMLButtonElement).click();
    (el.querySelector('.actions [aria-label="Keep"]') as HTMLButtonElement).click();
    (el.querySelector('.actions [aria-label="Toggle read"]') as HTMLButtonElement).click();
    (el.querySelector('.close') as HTMLButtonElement).click();
    expect(c).toEqual({ favorite: 1, keep: 1, read: 1, close: 1 });
  });

  it('emits openOriginal when the original-article link is clicked', () => {
    const f = mount(entry({ url: 'https://example.com/full-story' }));
    const emitted = jest.fn();
    f.componentInstance.openOriginal.subscribe(emitted);

    const link = f.debugElement.query(By.css('a[target="_blank"]'));
    link.triggerEventHandler('click', null);

    expect(emitted).toHaveBeenCalled();
  });

  it('carries the full-screen back button in its own toolbar, sliding out before close', () => {
    // Full-screen chrome belongs to the article, not the shell's bar (#128):
    // the toolbar rides the overlay, so the list's header underneath never has
    // to change — and the back button plays the slide-out (like a back-swipe)
    // rather than cutting straight to the list, so close waits for it.
    const f = TestBed.createComponent(ReaderViewComponent);
    f.componentRef.setInput('entry', entry());
    f.componentRef.setInput('fullscreen', true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    const close = jest.fn();
    f.componentInstance.close.subscribe(close);
    const back = el.querySelector('.bar .close') as HTMLButtonElement;
    expect(back).not.toBeNull();
    back.click();
    expect(close).not.toHaveBeenCalled();
    expect(f.componentInstance.leaving()).toBe(true);
    f.destroy();
  });

  // The panel reserves the floating app bar's height only in the split pane,
  // where the shell's bar floats above it (#97). Full-screen, the article rides
  // an overlay ABOVE that bar and brings its own in-flow toolbar, so a
  // reservation would be a blank strip. jsdom cannot see the resulting layout,
  // so pin the flag the stylesheet keys off instead.
  it('reserves the app bar only in the split pane, not full-screen', () => {
    const withBar = mount(entry()).nativeElement as HTMLElement;
    expect(withBar.querySelector('.reader')!.classList).toContain('with-bar');

    const f = TestBed.createComponent(ReaderViewComponent);
    f.componentRef.setInput('entry', entry());
    f.componentRef.setInput('fullscreen', true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.reader')!.classList).not.toContain('with-bar');
    f.destroy();
  });

  describe('full-screen toolbar hide-on-scroll', () => {
    function fullscreenMount() {
      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry());
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();
      return f;
    }

    function scrollHostTo(f: ReturnType<typeof mount>, top: number): void {
      const host = f.nativeElement as HTMLElement;
      host.scrollTop = top;
      host.dispatchEvent(new Event('scroll'));
      f.detectChanges();
    }

    it('opens presented, retracts scrolling down, returns scrolling up', () => {
      const f = fullscreenMount();
      const bar = (f.nativeElement as HTMLElement).querySelector('.bar')!;
      expect(bar.classList).not.toContain('hidden');

      scrollHostTo(f, 400); // down
      expect(bar.classList).toContain('hidden');

      scrollHostTo(f, 300); // up
      expect(bar.classList).not.toContain('hidden');
      f.destroy();
    });

    it('presents the toolbar anew for the next entry', () => {
      // prev/next reuse this component instance; a toolbar the previous
      // article's reading had retracted must not open the next one headless.
      const f = fullscreenMount();
      scrollHostTo(f, 400);
      expect((f.nativeElement as HTMLElement).querySelector('.bar')!.classList).toContain('hidden');

      f.componentRef.setInput('entry', entry({ id: 2 }));
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('.bar')!.classList).not.toContain(
        'hidden',
      );
      f.destroy();
    });

    it('never retracts the split-pane toolbar', () => {
      const f = mount(entry());
      const bar = (f.nativeElement as HTMLElement).querySelector('.bar')!;
      scrollHostTo(f, 100);
      scrollHostTo(f, 500);
      expect(bar.classList).not.toContain('hidden');
    });

    it('keeps the mini header while the toolbar below it retracts', () => {
      // The mini header is the only thing naming the article once the toolbar
      // is gone, so it must survive the very scroll that retracts the toolbar.
      const f = fullscreenMount();
      const el = f.nativeElement as HTMLElement;
      scrollHostTo(f, 400);

      expect(el.querySelector('.bar')!.classList).toContain('hidden');
      expect(el.querySelector('.mini')!.classList).not.toContain('hidden');
      expect(el.querySelector('.mini .mini-title')!.textContent).toContain('Deep dive');
      f.destroy();
    });
  });

  describe('mini header', () => {
    it('names the article with its favicon and title', () => {
      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry({ faviconUrl: 'https://x/f.png' }));
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('.mini .mini-title')!.textContent).toContain('Deep dive');
      expect(el.querySelector<HTMLImageElement>('.mini app-favicon img')!.src).toBe(
        'https://x/f.png',
      );
      f.destroy();
    });

    it('hides itself from assistive technology, which reads the h1 instead', () => {
      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry());
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();

      expect(
        (f.nativeElement as HTMLElement).querySelector('.mini')!.getAttribute('aria-hidden'),
      ).toBe('true');
      f.destroy();
    });

    it('rides inside the split pane’s toolbar instead of taking a strip of its own', () => {
      const el = mount(entry({ faviconUrl: 'https://x/f.png' })).nativeElement as HTMLElement;
      expect(el.querySelector('.mini')).toBeNull();
      expect(el.querySelector('.bar .bar-title')!.textContent).toContain('Deep dive');
      expect(el.querySelector<HTMLImageElement>('.bar app-favicon img')!.src).toBe(
        'https://x/f.png',
      );
    });

    it('offers favourite and keep in the split pane’s toolbar', () => {
      const f = mount(entry({ isFavorite: true }));
      const el = f.nativeElement as HTMLElement;
      const favourite = el.querySelector<HTMLButtonElement>('.bar [aria-label="Favorite"]')!;
      const keep = el.querySelector<HTMLButtonElement>('.bar [aria-label="Keep"]')!;

      // The toolbar reports the entry's state, the way the article's action row does.
      expect(favourite.classList).toContain('on');
      expect(keep.classList).not.toContain('on');

      const favouriteEmits = jest.fn();
      const keepEmits = jest.fn();
      f.componentInstance.favorite.subscribe(favouriteEmits);
      f.componentInstance.keep.subscribe(keepEmits);
      favourite.click();
      keep.click();
      expect(favouriteEmits).toHaveBeenCalled();
      expect(keepEmits).toHaveBeenCalled();
    });

    it('offers favourite and keep in the full-screen toolbar too', () => {
      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry());
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      // The nameplate still rides the .mini strip in full screen, not the bar.
      expect(el.querySelector('.bar .bar-title')).toBeNull();
      expect(el.querySelector('.bar [aria-label="Favorite"]')).not.toBeNull();
      expect(el.querySelector('.bar [aria-label="Keep"]')).not.toBeNull();
      f.destroy();
    });
  });

  describe('article refresh', () => {
    it('shows the refresh button in reader mode in both layouts', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const pane = mount(entry()).nativeElement as HTMLElement;
      expect(pane.querySelector('.bar [aria-label="Reload article"]')).not.toBeNull();

      const f = TestBed.createComponent(ReaderViewComponent);
      f.componentRef.setInput('entry', entry());
      f.componentRef.setInput('fullscreen', true);
      f.detectChanges();
      expect(
        (f.nativeElement as HTMLElement).querySelector('.bar [aria-label="Reload article"]'),
      ).not.toBeNull();
      f.destroy();
    });

    it('hides the refresh button once the reader switches to original', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const f = mount(entry());
      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('.bar [aria-label="Reload article"]')).not.toBeNull();

      f.componentInstance.toggleMode(); // reader -> original
      f.detectChanges();
      expect(el.querySelector('.bar [aria-label="Reload article"]')).toBeNull();
    });

    it('hides the refresh button when extraction failed (original fallback)', () => {
      // Default loadMock resolves failed, so the view falls back to original.
      const el = mount(entry()).nativeElement as HTMLElement;
      expect(el.querySelector('.bar [aria-label="Reload article"]')).toBeNull();
    });

    it('refetches past the cache and shows the loading state', () => {
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const subject = new Subject<ReaderContent>();
      reloadMock.mockReturnValue(subject.asObservable());
      const f = mount(entry());
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('.bar [aria-label="Reload article"]') as HTMLButtonElement).click();
      f.detectChanges();
      expect(reloadMock).toHaveBeenCalledWith(1);
      expect(el.querySelector('app-loading-overlay.shown')).not.toBeNull();

      subject.next(okContent({ contentHtml: '<p>FRESH</p>' }));
      subject.complete();
      f.detectChanges();
      expect(el.querySelector('app-loading-overlay.shown')).toBeNull();
      expect(el.querySelector('.content')!.innerHTML).toContain('FRESH');
    });

    it('refreshArticle does not reset the reader/original mode', () => {
      // The button is reader-only, but the method must leave the mode alone —
      // only a genuine entry change resets it.
      loadMock.mockReturnValue(of<ReaderContent>(okContent()));
      reloadMock.mockReturnValue(of<ReaderContent>(okContent()));
      const f = mount(entry());
      f.componentInstance.toggleMode(); // reader -> original
      expect(f.componentInstance.mode()).toBe('original');

      f.componentInstance.refreshArticle();
      f.detectChanges();
      expect(f.componentInstance.mode()).toBe('original');
    });
  });

  it('renders extracted reader content when extraction succeeds', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.content')!.innerHTML).toContain('READER');
  });

  it('falls back to feed content and shows a note when extraction fails', () => {
    loadMock.mockReturnValue(of<ReaderContent>(failedContent()));
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
    expect(el.querySelector('app-loading-overlay.shown')).not.toBeNull();
    expect(el.querySelector('.content')).toBeNull();
    // The overlay is decorative, so the article carries the busy state instead.
    expect(el.querySelector('article')!.getAttribute('aria-busy')).toBe('true');
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

  it('keeps the original hero after the reader hero fails to load', () => {
    loadMock.mockReturnValue(
      of<ReaderContent>(
        okContent({
          readerHero: { url: 'https://img.test/gone.jpg', width: null, height: null },
          originalHero: { url: 'https://img.test/feed.jpg', width: 800, height: 450 },
        }),
      ),
    );
    const f = mount(entry());
    hero(f)!.dispatchEvent(new Event('error'));
    f.detectChanges();
    expect(hero(f)).toBeNull();

    // The failure belongs to the one broken URL, not to the article: the
    // original view offers a different picture and must still show it.
    TestBed.inject(ReaderModeService).toggle();
    f.detectChanges();

    expect(hero(f)!.getAttribute('src')).toBe('https://img.test/feed.jpg');
  });

  it('renders no hero when the backend resolved none', () => {
    loadMock.mockReturnValue(of<ReaderContent>(okContent()));

    expect(hero(mount(entry()))).toBeNull();
  });

  it('falls back to the feed summary when contentHtml is null on failure', () => {
    loadMock.mockReturnValue(of<ReaderContent>(failedContent()));
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
      f.componentRef.setInput('fullscreen', true);
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
      (el.querySelector('.bar .close') as HTMLButtonElement).click();
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
