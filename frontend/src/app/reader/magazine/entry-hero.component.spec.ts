import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryHeroComponent } from './entry-hero.component';
import { EntryDto } from '../models';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';

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
  title: 'Big headline',
  url: null,
  author: null,
  summary: 'A meaningful summary.',
  excerpt: 'A meaningful summary.',
  imageUrl: 'https://x/a.jpg',
  imageWidth: null,
  imageHeight: null,
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryHeroComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryHeroComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

describe('EntryHeroComponent', () => {
  it('renders the headline, source and image', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.textContent).toContain('Big headline');
    expect(element.textContent).toContain('Src');
    expect(element.querySelector('img.img')).not.toBeNull();
  });

  it('emits open on click', () => {
    const fixture = mount(entry());
    const open = jest.fn();
    entryActions.open.mockImplementation(open);
    (fixture.nativeElement.querySelector('.hero') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });

  it('falls back to a text hero when the image errors', () => {
    const fixture = mount(entry());
    fixture.componentInstance.imgError.set(true);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('img.img')).toBeNull();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Big headline');
  });

  it('demotes a tiny image (tracking pixel) to a text hero', () => {
    const fixture = mount(entry());
    fixture.componentInstance.onLoad({ target: { naturalWidth: 100 } } as unknown as Event);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('img.img')).toBeNull();
  });

  it('sets the aspect ratio from the declared dimensions', () => {
    const element = mount(
      entry({ imageUrl: 'https://i/a.jpg', imageWidth: 1232, imageHeight: 1232 }),
    ).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.style.aspectRatio).toBe('1232 / 1232');
    expect(img.getAttribute('width')).toBe('1232');
    expect(img.getAttribute('height')).toBe('1232');
  });

  it('falls back to 16 / 9 when the feed declared no dimensions', () => {
    const element = mount(entry({ imageUrl: 'https://i/a.jpg' })).nativeElement as HTMLElement;
    const img = element.querySelector('img.img') as HTMLImageElement;
    expect(img.style.aspectRatio).toBe('16 / 9');
    expect(img.getAttribute('width')).toBeNull();
  });

  it('carries the actions on the meta row, not on a row of its own', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    expect(element.querySelector('.actions')).toBeNull();
    expect(element.querySelectorAll('app-entry-meta app-entry-actions button').length).toBe(3);
  });

  it('emits favorite, keep and read from the meta row', () => {
    const fixture = mount(entry());
    const favorite = jest.fn();
    const keep = jest.fn();
    const read = jest.fn();
    entryActions.favorite.mockImplementation(favorite);
    entryActions.keep.mockImplementation(keep);
    entryActions.toggleRead.mockImplementation(read);

    const buttons = fixture.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(favorite).toHaveBeenCalled();
    expect(keep).toHaveBeenCalled();
    expect(read).toHaveBeenCalled();
  });

  it('exposes its entry id on the host element', () => {
    const fixture = mount(entry({ id: 77 }));
    expect(fixture.nativeElement.getAttribute('data-entry-id')).toBe('77');
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
});
