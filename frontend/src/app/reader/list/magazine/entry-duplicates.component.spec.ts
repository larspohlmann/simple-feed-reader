import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { EntryDto } from '../../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry } from '../../../../testing/entry-testing';
import { authorlessHomePost, holdUnrenamedHome } from '../../../../testing/unrenamed-home-testing';

const entryActions = {
  favorite: jest.fn(),
  like: jest.fn().mockReturnValue(true),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
  entryActions.like.mockReturnValue(true);
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
    providers: [{ provide: EntryActionHandler, useValue: entryActions }, provideRouter([])],
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

it('flips the like in the popover and re-emits like for the copy (#1600)', () => {
  const dup = entry({ id: 2, title: 'Skeet', social: { network: 'bluesky', isLiked: false } });
  const fixture = mount(entry({ duplicates: [dup] }));

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();
  const likeButton = overlay.querySelector(
    '.dup-popover button[aria-label="Like on Bluesky"]',
  ) as HTMLElement;

  likeButton.click();
  fixture.detectChanges();

  expect(entryActions.like).toHaveBeenCalledWith(dup, expect.any(Function));
  expect(likeButton.getAttribute('aria-pressed')).toBe('true');
});

function openLikePopover(sent: boolean) {
  const dup = entry({ id: 2, title: 'Skeet', social: { network: 'bluesky', isLiked: false } });
  const fixture = mount(entry({ duplicates: [dup] }));
  entryActions.like.mockReturnValue(sent);
  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();
  const button = overlay.querySelector(
    '.dup-popover button[aria-label$="on Bluesky"]',
  ) as HTMLElement;
  return { fixture, button };
}

it('leaves the popover like alone when the shell dropped the click (#1600)', () => {
  const { fixture, button } = openLikePopover(false);

  button.click();
  fixture.detectChanges();

  expect(button.getAttribute('aria-pressed')).toBe('false');
});

it('reverts the popover like when the network refuses it (#1600)', () => {
  const { fixture, button } = openLikePopover(true);
  let refuse: () => void = () => undefined;
  entryActions.like.mockImplementation((_copy: EntryDto, onFailure: () => void) => {
    refuse = onFailure;
    return true;
  });

  button.click();
  fixture.detectChanges();
  expect(button.getAttribute('aria-pressed')).toBe('true');
  refuse();
  fixture.detectChanges();

  expect(button.getAttribute('aria-pressed')).toBe('false');
});
