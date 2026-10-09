import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryRowComponent } from './entry-row.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryDto, ImageRenditionDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { neverRecoveringImageProxy } from '../../../../testing/image-proxy-testing';
import {
  describeShortMarking,
  describePortraitCover,
} from '../../../../testing/short-marking-testing';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Hello',
  url: 'https://x/1',
  author: null,
  summary: '<p>Summary text</p>',
  excerpt: 'Summary text',
  imageUrl: 'https://cdn.test/a.jpg',
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'heise',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto) {
  const fixture = TestBed.createComponent(EntryRowComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

/** jsdom does not fire `click` for a focused button's Enter/Space itself, so
 *  this reproduces it: Enter's click follows keydown, Space's follows keyup —
 *  skipped once that governing event's default was prevented. */
function pressEnter(target: HTMLElement): void {
  const keydown = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
  target.dispatchEvent(keydown);
  if (!keydown.defaultPrevented) {
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }
}

function pressSpace(target: HTMLElement): void {
  const keydown = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
  target.dispatchEvent(keydown);
  const keyup = new KeyboardEvent('keyup', { key: ' ', bubbles: true, cancelable: true });
  target.dispatchEvent(keyup);
  if (!keydown.defaultPrevented && !keyup.defaultPrevented) {
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }
}

describe('EntryRowComponent', () => {
  describeShortMarking((over) => mount(entry(over)));
  describePortraitCover((over) => mount(entry(over)));

  let imageProxy: ReturnType<typeof neverRecoveringImageProxy>;

  beforeEach(() => {
    imageProxy = neverRecoveringImageProxy();
    TestBed.configureTestingModule({
      imports: [EntryRowComponent, provideTranslocoTesting()],
      providers: [
        { provide: EntryActionHandler, useValue: entryActions },
        { provide: ImageProxyService, useValue: imageProxy },
        provideRouter([]),
      ],
    });
  });

  it('renders title, source, snippet and the https thumbnail', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.title')!.textContent).toContain('Hello');
    expect(element.querySelector('.meta')!.textContent).toContain('heise');
    expect(element.querySelector('.snippet')!.textContent).toContain('Summary text');
    expect(element.querySelector('img.thumb')!.getAttribute('src')).toBe('https://cdn.test/a.jpg');
  });

  it('omits the thumbnail when the entry has no persisted image', () => {
    const element = mount(entry({ imageUrl: null })).nativeElement as HTMLElement;
    expect(element.querySelector('img.thumb')).toBeNull();
  });

  it('shows the persisted imageUrl', () => {
    const element = mount(entry({ imageUrl: 'https://cdn.test/hero.jpg' }))
      .nativeElement as HTMLElement;
    expect(element.querySelector('img.thumb')!.getAttribute('src')).toBe(
      'https://cdn.test/hero.jpg',
    );
  });

  it('hides a thumbnail that fails to load, without a proxy retry', () => {
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector('img.thumb')!.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(element.querySelector('img.thumb')).toBeNull();
    expect(imageProxy.attempts).toEqual([]);
  });

  it('moves the thumbnail to the left when imageSide is left', () => {
    const fixture = mount(entry());
    fixture.componentRef.setInput('imageSide', 'left');
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.row')!.classList).toContain('img-left');
  });

  it('emits actions and open', () => {
    const fixture = mount(entry());
    const out = { favorite: 0, keep: 0, read: 0, open: 0 };
    entryActions.favorite.mockImplementation(() => out.favorite++);
    entryActions.keep.mockImplementation(() => out.keep++);
    entryActions.toggleRead.mockImplementation(() => out.read++);
    entryActions.open.mockImplementation(() => out.open++);
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('[aria-label="Favorite"]') as HTMLButtonElement).click();
    (element.querySelector('[aria-label="Keep"]') as HTMLButtonElement).click();
    (element.querySelector('[aria-label="Toggle read"]') as HTMLButtonElement).click();
    (element.querySelector('.row') as HTMLElement).click();
    expect(out).toEqual({ favorite: 1, keep: 1, read: 1, open: 1 });
  });

  it('keeps the list its larger md action glyphs', () => {
    const actions = mount(entry()).debugElement.query(By.directive(EntryActionsComponent));
    expect(actions.componentInstance.size()).toBe('md');
  });

  it('favorites exactly once on Enter over an action, and does not open the entry', () => {
    const fixture = mount(entry());
    const out = { favorite: 0, open: 0 };
    entryActions.favorite.mockImplementation(() => out.favorite++);
    entryActions.open.mockImplementation(() => out.open++);

    pressEnter(fixture.nativeElement.querySelector('[aria-label="Favorite"]') as HTMLElement);
    fixture.detectChanges();

    expect(out).toEqual({ favorite: 1, open: 0 });
  });

  it('favorites exactly once on Space over an action, and does not open the entry', () => {
    const fixture = mount(entry());
    const out = { favorite: 0, open: 0 };
    entryActions.favorite.mockImplementation(() => out.favorite++);
    entryActions.open.mockImplementation(() => out.open++);

    pressSpace(fixture.nativeElement.querySelector('[aria-label="Favorite"]') as HTMLElement);
    fixture.detectChanges();

    expect(out).toEqual({ favorite: 1, open: 0 });
  });

  it('renders a pill for each saved search the entry belongs to (#1118)', () => {
    const element = mount(entry({ savedSearches: [{ id: 1, slug: '1-climate', term: 'climate' }] }))
      .nativeElement as HTMLElement;
    const pill = element.querySelector('a.pill.saved-search')!;
    expect(pill.textContent).toContain('climate');
    expect(pill.getAttribute('href')).toContain('/searches/saved/1-climate');
  });

  it('renders no saved-search pill when the entry matches none (#1118)', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('a.pill.saved-search')).toBeNull();
    expect(element.querySelector('.saved-search-pill')).toBeNull();
  });

  it('exposes its entry id on the host element', () => {
    const fixture = mount(entry({ id: 4242 }));
    expect(fixture.nativeElement.getAttribute('data-entry-id')).toBe('4242');
  });

  it('bubbles open for a duplicate copy through the footer', () => {
    const dup = entry({ id: 9, source: 'NDR SH' });
    const fixture = mount(entry({ duplicates: [dup] }));
    const opened = jest.fn();
    entryActions.open.mockImplementation(opened);
    (
      fixture.nativeElement.querySelector('app-entry-duplicates .also-entry') as HTMLElement
    ).click();
    fixture.detectChanges();
    const overlay = TestBed.inject(OverlayContainer).getContainerElement();
    (overlay.querySelector('.dup-popover app-entry-row .row') as HTMLElement).click();
    expect(opened).toHaveBeenCalledWith(dup);
    expect(opened).toHaveBeenCalledTimes(1);
  });

  it('offers its renditions to the browser at the 88×66 cover box, up to a 2:1 picture', () => {
    const renditions: ImageRenditionDto[] = [
      { url: 'https://cdn.test/a-150.jpg', width: 150 },
      { url: 'https://cdn.test/a-300.jpg', width: 300 },
    ];
    const element = mount(entry({ imageRenditions: renditions })).nativeElement as HTMLElement;
    const img = element.querySelector('img.thumb') as HTMLImageElement;
    expect(img.getAttribute('srcset')).toBe(
      'https://cdn.test/a-150.jpg 150w, https://cdn.test/a-300.jpg 300w',
    );
    expect(img.getAttribute('sizes')).toBe('132px');
  });
});
