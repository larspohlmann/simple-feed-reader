import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { EntryDto } from '../../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry } from '../../../../testing/entry-testing';
import { authorlessHomePost, holdUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';

const entryActions = {
  favorite: jest.fn(),
  like: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    publishedAt: '2026-07-05T09:00:00Z',
    createdAt: '2026-07-05T09:00:00Z',
    ...over,
  });

function mount(testEntry: EntryDto, arrange?: () => void) {
  TestBed.configureTestingModule({
    imports: [EntryDuplicatesComponent, provideTranslocoTesting()],
    providers: [
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: entryActions },
      provideRouter([]),
    ],
  });
  arrange?.();
  const fixture = TestBed.createComponent(EntryDuplicatesComponent);
  fixture.componentRef.setInput('entry', testEntry);
  fixture.detectChanges();
  return fixture;
}

it('renders nothing without duplicates', () => {
  const element = mount(entry()).nativeElement as HTMLElement;
  expect(element.querySelector('.also-foot')).toBeNull();
});

it('renders one chip per duplicate with its feed', () => {
  const dup = entry({ id: 2, source: 'NDR Schleswig-Holstein' });
  const element = mount(entry({ duplicates: [dup] })).nativeElement as HTMLElement;
  const chips = element.querySelectorAll('.also-entry');
  expect(chips.length).toBe(1);
  expect(chips[0].textContent).toContain('NDR Schleswig-Holstein');
});

it("names a duplicate from a Home by the Home's label", () => {
  const dup = authorlessHomePost(7, { id: 2, source: 'Bob' });
  const element = mount(entry({ duplicates: [dup] }), () => holdUnrenamedHome(7))
    .nativeElement as HTMLElement;

  expect(element.querySelector('.also-entry')!.textContent).toContain('Home@bsky');
});

it('names a duplicate by its feed, not by the poster', () => {
  const dup = entry({
    id: 2,
    source: 'Home@bsky',
    poster: { name: 'Bob', url: null, imageUrl: null },
  });
  const element = mount(entry({ duplicates: [dup] })).nativeElement as HTMLElement;
  const chip = element.querySelector('.also-entry')!;
  expect(chip.textContent).toContain('Home@bsky');
  expect(chip.textContent).not.toContain('Bob');
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

it('re-emits favorite for the copy and shows the flag the store then holds', () => {
  const dup = entry({ id: 2, title: 'NDR wording', source: 'NDR SH' });
  const fixture = mount(entry({ duplicates: [dup] }));

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const favoriteButton = () =>
    TestBed.inject(OverlayContainer)
      .getContainerElement()
      .querySelector('.dup-popover button[aria-label="Favorite"]') as HTMLElement;

  favoriteButton().click();
  expect(entryActions.favorite).toHaveBeenCalledWith(dup);
  fixture.componentRef.setInput('entry', entry({ duplicates: [{ ...dup, isFavorite: true }] }));
  fixture.detectChanges();

  expect(favoriteButton().classList.contains('on')).toBe(true);
  expect(favoriteButton().getAttribute('aria-pressed')).toBe('true');
});

function openLikePopover() {
  const dup = entry({
    id: 2,
    title: 'Skeet',
    social: { network: 'bluesky', isLiked: false, canLike: true },
  });
  const fixture = mount(entry({ duplicates: [dup] }));
  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const button = () =>
    TestBed.inject(OverlayContainer)
      .getContainerElement()
      .querySelector('.dup-popover button[aria-label$="on Bluesky"]') as HTMLElement;
  const storeHolds = (isLiked: boolean) => {
    fixture.componentRef.setInput(
      'entry',
      entry({ duplicates: [{ ...dup, social: { network: 'bluesky', isLiked, canLike: true } }] }),
    );
    fixture.detectChanges();
  };
  return { fixture, dup, button, storeHolds };
}

it('re-emits like for the copy and follows the store through the flip and its revert (#1600)', () => {
  const { dup, button, storeHolds } = openLikePopover();

  button().click();
  expect(entryActions.like).toHaveBeenCalledWith(dup);
  storeHolds(true);
  expect(button().getAttribute('aria-pressed')).toBe('true');
  storeHolds(false);

  expect(button().getAttribute('aria-pressed')).toBe('false');
});

it('never flips the like on its own (#1600)', () => {
  const { fixture, button } = openLikePopover();

  button().click();
  fixture.detectChanges();

  expect(button().getAttribute('aria-pressed')).toBe('false');
});

it('reopens the popover on the like the store wrote into the row copy (#1600)', () => {
  const { fixture, storeHolds } = openLikePopover();
  fixture.componentInstance.close();
  storeHolds(true);

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();

  const reopened = TestBed.inject(OverlayContainer)
    .getContainerElement()
    .querySelector('.dup-popover button[aria-label$="on Bluesky"]') as HTMLElement;
  expect(reopened.getAttribute('aria-pressed')).toBe('true');
});
