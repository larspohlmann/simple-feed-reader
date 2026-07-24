import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { of, Subject } from 'rxjs';
import { ReaderViewComponent } from './reader-view.component';
import { ReaderContentService } from '../reader-content.service';
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
