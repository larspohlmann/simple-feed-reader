import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { EntryDto } from '../../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
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

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Main',
  url: null,
  author: null,
  summary: null,
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: '2026-07-05T09:00:00Z',
  createdAt: '2026-07-05T09:00:00Z',
  subscriptionId: 1,
  source: 'tagesschau',
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
  TestBed.configureTestingModule({
    imports: [EntryDuplicatesComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
  });
  const fixture = TestBed.createComponent(EntryDuplicatesComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

it('renders nothing without duplicates', () => {
  const element = mount(entry()).nativeElement as HTMLElement;
  expect(element.querySelector('.also-foot')).toBeNull();
});

it('renders one chip per duplicate with its source', () => {
  const dup = entry({ id: 2, source: 'NDR Schleswig-Holstein' });
  const element = mount(entry({ duplicates: [dup] })).nativeElement as HTMLElement;
  const chips = element.querySelectorAll('.also-entry');
  expect(chips.length).toBe(1);
  expect(chips[0].textContent).toContain('NDR Schleswig-Holstein');
});

it('opens a popover with the copy card and re-emits open for that copy', () => {
  const dup = entry({ id: 2, title: 'NDR wording', source: 'NDR SH' });
  const fixture = mount(entry({ duplicates: [dup] }));
  const opened = jest.fn();
  entryActions.open.mockImplementation(opened);

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();
  const panel = overlay.querySelector('.dup-popover');
  expect(panel).not.toBeNull();
  expect(panel!.textContent).toContain('NDR wording');

  (panel!.querySelector('app-entry-row .row') as HTMLElement).click();
  expect(opened).toHaveBeenCalledWith(dup);
});

it('closes the popover once the copy is opened', () => {
  const dup = entry({ id: 2, title: 'NDR wording', source: 'NDR SH' });
  const fixture = mount(entry({ duplicates: [dup] }));
  const opened = jest.fn();
  entryActions.open.mockImplementation(opened);

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();

  (overlay.querySelector('.dup-popover app-entry-row .row') as HTMLElement).click();
  fixture.detectChanges();

  expect(opened).toHaveBeenCalledWith(dup);
  expect(overlay.querySelector('.dup-popover')).toBeNull();
});

it('flips the favorite icon in the popover and re-emits favorite for the copy', () => {
  const dup = entry({ id: 2, title: 'NDR wording', source: 'NDR SH' });
  const fixture = mount(entry({ duplicates: [dup] }));
  const favorited = jest.fn();
  entryActions.favorite.mockImplementation(favorited);

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();
  const favoriteButton = overlay.querySelector(
    '.dup-popover button[aria-label="Favorite"]',
  ) as HTMLElement;

  favoriteButton.click();
  fixture.detectChanges();

  expect(favorited).toHaveBeenCalledWith(dup);
  expect(favoriteButton.classList.contains('on')).toBe(true);
  expect(favoriteButton.getAttribute('aria-pressed')).toBe('true');
});
