import { EMPTY } from 'rxjs';
import { Component, Provider } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { EntryDto } from '../../models';
import { ListItem, leadOf, storyOf } from '../../state/list-item';
import { StoryContextDirective } from '../../trending/story-context';
import { StoryArticleDto, TrendingStoryDto } from '../../trending/trending.models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry, aStory, aStoryArticle } from '../../../../testing/entry-testing';
import { authorlessHomePost, holdUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { provideStoryContext } from '../../../../testing/story-context-testing';
import { provideSocialAccounts } from '../../../../testing/social-accounts-testing';
import { provideStoryActions } from '../../../../testing/story-actions-testing';

const entryActions = {
  favorite: jest.fn(),
  like: jest.fn(),
  vote: jest.fn(),
  keep: jest.fn(),
  unshare: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
  deletePost: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
  entryActions.like.mockReturnValue(EMPTY);
  entryActions.vote.mockReturnValue(EMPTY);
});

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    publishedAt: '2026-07-05T09:00:00Z',
    createdAt: '2026-07-05T09:00:00Z',
    ...over,
  });

const article = (over: Partial<StoryArticleDto> = {}): StoryArticleDto =>
  aStoryArticle({ ...entry(), ...over });

function mount(testEntry: ListItem, arrange?: () => void, storyActions: Provider[] = []) {
  TestBed.configureTestingModule({
    imports: [EntryDuplicatesComponent, provideTranslocoTesting()],
    providers: [
      ...storyActions,
      provideStoryContext(storyOf(testEntry)).provider,
      provideSocialAccounts(),
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: entryActions },
      provideRouter([]),
    ],
  });
  arrange?.();
  const fixture = TestBed.createComponent(EntryDuplicatesComponent);
  hold(fixture, testEntry);
  return fixture;
}

function hold(fixture: ComponentFixture<EntryDuplicatesComponent>, row: ListItem): void {
  fixture.componentRef.setInput('entry', leadOf(row));
  fixture.detectChanges();
}

it('renders nothing without duplicates', () => {
  const element = mount(entry()).nativeElement as HTMLElement;
  expect(element.querySelector('.also-foot')).toBeNull();
});

describe("a Trending story's footer in the list layout (#2128)", () => {
  const footOf = (row: ListItem, storyActions = provideStoryActions()) =>
    (mount(row, undefined, storyActions).nativeElement as HTMLElement).querySelector('.also-foot');

  it('leads with the story actions, before the other outlets', () => {
    const foot = footOf(aStory({ articles: [article(), article({ id: 2 })] }))!;

    expect(foot.firstElementChild!.localName).toBe('app-story-actions');
    expect(foot.querySelectorAll('.story-actions button').length).toBe(2);
    expect(foot.querySelectorAll('.also-entry').length).toBe(1);
  });

  it('still shows for a story with no other articles, holding only its actions', () => {
    const foot = footOf(aStory({ articles: [article()] }))!;

    expect(foot.querySelector('.lead')).toBeNull();
    expect(foot.querySelectorAll('.story-actions button').length).toBe(2);
  });

  it('offers no story actions off Trending', () => {
    const foot = footOf(
      aStory({ articles: [article(), article({ id: 2 })] }),
      provideStoryActions('all'),
    )!;

    expect(foot.querySelector('.story-actions')).toBeNull();
  });
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

it("names a Trending story's post by its poster, not by the timeline holding it", () => {
  const dup = article({
    id: 2,
    source: 'Home@bsky',
    poster: { name: 'Bob', url: null, imageUrl: null },
  });
  const element = mount(
    aStory({ outletCount: 2, viewed: false, shareCount: 0, articles: [article(), dup] }),
  ).nativeElement as HTMLElement;
  const chip = element.querySelector('.also-entry')!;

  expect(chip.textContent).toContain('Bob');
  expect(chip.textContent).not.toContain('Home@bsky');
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

it("deletes the row's post from a copy and closes the popover (#1972)", () => {
  const own = {
    network: 'bluesky',
    isLiked: false,
    canLike: true,
    isOwn: true,
  } as const;
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
          {
            ...dup,
            social: { network: 'bluesky', isLiked, canLike: true, isOwn: false },
          },
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

it('re-emits a vote for the copy to the shell actions (#2218)', () => {
  const dup = entry({ id: 99, score: 5, myVote: 0 });
  const fixture = mount(entry({ duplicates: [dup] }));

  fixture.componentInstance.vote(dup, -1);

  expect(entryActions.vote).toHaveBeenCalledWith(dup, -1);
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

it("heads a row's duplicates as the places it also ran in", () => {
  const element = mount(entry({ duplicates: [entry({ id: 2 })] })).nativeElement as HTMLElement;

  expect(element.querySelector('.lead')!.textContent!.trim()).toBe('Also in');
});

it("heads a Trending story's other outlets as the feeds that also covered it", () => {
  const story = aStory({
    outletCount: 3,
    viewed: false,
    shareCount: 0,
    articles: [article(), article({ id: 2 })],
  });
  const element = mount(story).nativeElement as HTMLElement;

  expect(element.querySelector('.lead')!.textContent!.trim()).toBe('Also covered by');
});

describe('the footer icon', () => {
  const iconOf = (row: ListItem) =>
    (mount(row).nativeElement as HTMLElement).querySelector('app-icon.layers')!.textContent!.trim();

  it("marks a Trending story's other outlets with the Trending icon", () => {
    const story = aStory({
      outletCount: 3,
      viewed: false,
      shareCount: 0,
      articles: [article(), article({ id: 2 })],
    });

    expect(iconOf(story)).toBe('trending_up');
  });

  it("marks a row's copies with layers", () => {
    expect(iconOf(entry({ duplicates: [entry({ id: 2 })] }))).toBe('layers');
  });
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

  it('reveals the rest in place, without the click reaching the row around it', () => {
    const fixture = mount(entry({ duplicates: six() }));
    const element = fixture.nativeElement as HTMLElement;
    const row = document.createElement('div');
    const rowOpened = jest.fn();
    row.addEventListener('click', rowOpened);
    row.appendChild(element);

    element.querySelector<HTMLButtonElement>('.also-more')!.click();
    fixture.detectChanges();

    expect(element.querySelectorAll('.also-entry')).toHaveLength(6);
    expect(element.querySelector('.also-more')).toBeNull();
    expect(rowOpened).not.toHaveBeenCalled();
  });

  it('offers no "+N more" for exactly four', () => {
    const element = mount(entry({ duplicates: six().slice(0, 4) })).nativeElement as HTMLElement;

    expect(element.querySelectorAll('.also-entry')).toHaveLength(4);
    expect(element.querySelector('.also-more')).toBeNull();
  });
});

@Component({
  imports: [EntryDuplicatesComponent, StoryContextDirective],
  template: '<app-entry-duplicates [appStory]="story" [entry]="lead" />',
})
class StoryCardHostComponent {
  story: TrendingStoryDto = aStory();
  lead: EntryDto = article();
}

it("shows a copy's row in the popover without the story's pieces", () => {
  const lead = article();
  const copy = article({ id: 2, title: 'Second outlet', source: 'NDR SH' });
  const story = aStory({
    outletCount: 2,
    viewed: false,
    shareCount: 2,
    articles: [lead, copy],
  });
  TestBed.configureTestingModule({
    imports: [StoryCardHostComponent, provideTranslocoTesting()],
    providers: [
      ...provideStoryActions(),
      provideSocialAccounts(),
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: entryActions },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(StoryCardHostComponent);
  fixture.componentInstance.story = story;
  fixture.componentInstance.lead = lead;
  fixture.detectChanges();

  (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  fixture.detectChanges();

  const panel = TestBed.inject(OverlayContainer)
    .getContainerElement()
    .querySelector('.dup-popover')!;
  expect(panel.querySelector('app-entry-row')).not.toBeNull();
  expect(fixture.nativeElement.querySelector('app-story-actions')).not.toBeNull();
  expect(panel.querySelector('app-story-source-count')).toBeNull();
  expect(panel.querySelector('app-story-sharers')).toBeNull();
  expect(panel.querySelector('app-story-actions')).toBeNull();
});

describe('a Lemmy cross-post chip (#2420)', () => {
  const lead = () =>
    entry({ canonicalItemId: 1, community: { name: 'games', url: 'https://lemmy.world/c/games' } });
  const crossPost = (over: Partial<EntryDto> = {}) =>
    entry({
      id: 2,
      canonicalItemId: 2,
      community: { name: 'retrogaming', url: 'https://lemmy.world/c/retrogaming' },
      score: 12,
      commentCount: 3,
      ...over,
    });
  const chipOf = (duplicate: EntryDto) =>
    mount(entry({ ...lead(), duplicates: [duplicate] })).nativeElement.querySelector(
      '.also-entry',
    ) as HTMLElement;

  it('labels a cross-post chip by its community, with its score and comment count', () => {
    const chip = chipOf(crossPost());
    const labels = Array.from(chip.querySelectorAll('[role="img"]')).map((icon) =>
      icon.getAttribute('aria-label'),
    );

    expect(chip.querySelector('.feed')!.textContent).toBe('c/retrogaming');
    expect(chip.textContent).toContain('12');
    expect(chip.textContent).toContain('3');
    expect(labels).toEqual(['12 points', '3 comments']);
    expect(chip.querySelector('.t')).toBeNull();
  });

  it('opens a cross-post in the reader on click, with no popover', () => {
    const duplicate = crossPost();
    chipOf(duplicate).click();

    expect(entryActions.open).toHaveBeenCalledWith(duplicate);
    expect(
      TestBed.inject(OverlayContainer).getContainerElement().querySelector('.dup-popover'),
    ).toBeNull();
  });

  it('omits the score and the count a cross-post does not have', () => {
    expect(
      chipOf(crossPost({ score: null, commentCount: null })).querySelector('[role="img"]'),
    ).toBeNull();
  });

  it('keeps the popover for a copy of the same item', () => {
    const fixture = mount(entry({ ...lead(), duplicates: [crossPost({ canonicalItemId: 1 })] }));
    (fixture.nativeElement.querySelector('.also-entry') as HTMLElement).click();
    fixture.detectChanges();

    expect(
      TestBed.inject(OverlayContainer).getContainerElement().querySelector('.dup-popover'),
    ).not.toBeNull();
  });
});
