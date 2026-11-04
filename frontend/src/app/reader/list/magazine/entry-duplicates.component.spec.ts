import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { EntryDto } from '../../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry } from '../../../../testing/entry-testing';
import { authorlessHomePost, holdUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { ENTRY_ROW_ACTION, EntryRowAction } from '../../entry/entry-actions/entry-row-action';

const entryActions = {
  favorite: jest.fn(),
  like: jest.fn(),
  keep: jest.fn(),
  unshare: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
  deletePost: jest.fn(),
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

function mount(testEntry: EntryDto, arrange?: () => void, label = 'reader.alsoIn') {
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
  hold(fixture, testEntry, label);
  return fixture;
}

/** What a host binds: the row, and its duplicates as the copies. */
function hold(
  fixture: ComponentFixture<EntryDuplicatesComponent>,
  row: EntryDto,
  label = 'reader.alsoIn',
): void {
  fixture.componentRef.setInput('entry', row);
  fixture.componentRef.setInput('copies', row.duplicates ?? []);
  fixture.componentRef.setInput('label', label);
  fixture.detectChanges();
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

it("holds a long feed title in the chip's truncating name, the whole title in its tooltip", () => {
  const title = 'World News, Today World News, Latest International News - Times of India';
  const dup = entry({ id: 2, source: title });
  const element = mount(entry({ duplicates: [dup] })).nativeElement as HTMLElement;
  const chip = element.querySelector('.also-entry')!;

  expect(chip.querySelector('.feed')!.textContent!.trim()).toBe(title);
  expect(chip.getAttribute('title')).toBe(title);
});

it.each(['Enter', ' '])(
  'keeps a chip\'s "%s" key from reaching the card, which would open the row',
  (key) => {
    const element = mount(entry({ duplicates: [entry({ id: 2 })] })).nativeElement as HTMLElement;
    const card = jest.fn();
    element.addEventListener('keydown', card);

    element
      .querySelector('.also-entry')!
      .dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

    expect(card).not.toHaveBeenCalled();
  },
);

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

it("gives the copies in the popover none of the list's own row action", () => {
  const listAction: EntryRowAction = {
    icon: 'visibility_off',
    label: 'test.listAction',
    hint: 'test.listAction',
    run: jest.fn(),
  };
  const dup = entry({ id: 2, source: 'NDR SH' });
  const fixture = mount(entry({ duplicates: [dup] }), () =>
    TestBed.configureTestingModule({
      providers: [{ provide: ENTRY_ROW_ACTION, useValue: signal(listAction) }],
    }),
  );

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const panel = TestBed.inject(OverlayContainer)
    .getContainerElement()
    .querySelector('.dup-popover')!;

  expect(panel.querySelector('app-entry-row')).not.toBeNull();
  expect(panel.querySelector('[aria-label="test.listAction"]')).toBeNull();
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

it("deletes the row's post from a copy and closes the popover (#1972)", () => {
  const own = { network: 'bluesky', isLiked: false, canLike: true, isOwn: true } as const;
  const dup = entry({ id: 2, title: 'Copy', social: own });
  const row = entry({ id: 1, social: own, duplicates: [dup] });
  const fixture = mount(row);

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();
  (overlay.querySelector('.dup-popover [aria-label="Delete post"]') as HTMLElement).click();
  fixture.detectChanges();

  expect(entryActions.deletePost).toHaveBeenCalledWith(row);
  expect(overlay.querySelector('.dup-popover')).toBeNull();
});

it('removes a shared copy from Shared through the shell', () => {
  const dup = entry({ id: 2, title: 'Copy', isShared: true });
  const fixture = mount(entry({ duplicates: [dup] }));

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const overlay = TestBed.inject(OverlayContainer).getContainerElement();
  (overlay.querySelector('.dup-popover [aria-label="Remove from shared"]') as HTMLElement).click();

  expect(entryActions.unshare).toHaveBeenCalledWith(dup);
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
  hold(fixture, entry({ duplicates: [{ ...dup, isFavorite: true }] }));

  expect(favoriteButton().classList.contains('on')).toBe(true);
  expect(favoriteButton().getAttribute('aria-pressed')).toBe('true');
});

function openLikePopover() {
  const dup = entry({
    id: 2,
    title: 'Skeet',
    social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
  });
  const fixture = mount(entry({ duplicates: [dup] }));
  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();
  const button = () =>
    TestBed.inject(OverlayContainer)
      .getContainerElement()
      .querySelector('.dup-popover button[aria-label$="on Bluesky"]') as HTMLElement;
  const storeHolds = (isLiked: boolean) => {
    hold(
      fixture,
      entry({
        duplicates: [
          { ...dup, social: { network: 'bluesky', isLiked, canLike: true, isOwn: false } },
        ],
      }),
    );
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

it('heads the chips with the label it is given', () => {
  const dup = entry({ id: 2 });
  const element = mount(entry({ duplicates: [dup] }), undefined, 'reader.alsoPublishedAs')
    .nativeElement as HTMLElement;

  expect(element.querySelector('.lead')!.textContent!.trim()).toBe('Also published as');
});

it("lists the copies it is given, not the row's own duplicates", () => {
  const fixture = mount(entry({ duplicates: [entry({ id: 2, source: 'Row copy' })] }));
  fixture.componentRef.setInput('copies', [entry({ id: 3, source: 'Given copy' })]);
  fixture.detectChanges();

  const chips = (fixture.nativeElement as HTMLElement).querySelectorAll('.also-entry');
  expect([...chips].map((chip) => chip.textContent)).toEqual([
    expect.stringContaining('Given copy'),
  ]);
});

describe('more than four copies', () => {
  const six = () => [2, 3, 4, 5, 6, 7].map((id) => entry({ id, source: `Feed ${id}` }));

  it('names the first four and offers the rest behind "+N more"', () => {
    const element = mount(entry({ duplicates: six() })).nativeElement as HTMLElement;

    const chips = [...element.querySelectorAll('.also-entry')];
    expect(chips.map((chip) => chip.textContent)).toEqual([
      expect.stringContaining('Feed 2'),
      expect.stringContaining('Feed 3'),
      expect.stringContaining('Feed 4'),
      expect.stringContaining('Feed 5'),
    ]);
    expect(element.querySelector('.also-more')!.textContent!.trim()).toBe('+2 more');
  });

  it('reveals the rest in place, without opening the row', () => {
    const fixture = mount(entry({ duplicates: six() }));
    const element = fixture.nativeElement as HTMLElement;

    element.querySelector<HTMLButtonElement>('.also-more')!.click();
    fixture.detectChanges();

    expect(element.querySelectorAll('.also-entry')).toHaveLength(6);
    expect(element.querySelector('.also-more')).toBeNull();
    expect(entryActions.open).not.toHaveBeenCalled();
  });

  it('offers no "+N more" for exactly four', () => {
    const element = mount(entry({ duplicates: six().slice(0, 4) })).nativeElement as HTMLElement;

    expect(element.querySelectorAll('.also-entry')).toHaveLength(4);
    expect(element.querySelector('.also-more')).toBeNull();
  });
});
