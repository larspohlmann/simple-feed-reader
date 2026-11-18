import { Provider } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryDto } from '../../models';
import { ListItem, leadOf, storyOf as storyOfItem } from '../../state/list-item';
import { StoryArticleDto } from '../../trending/trending.models';
import { MoreSourcesComponent } from './more-sources.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { aLinkCard, anEntry, aStory, aStoryArticle } from '../../../../testing/entry-testing';
import { provideStoryContext } from '../../../../testing/story-context-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { provideStoryActions } from '../../../../testing/story-actions-testing';

const open = jest.fn();
const navigation = { layer: jest.fn(), openAlone: jest.fn() };
let fixture: ComponentFixture<MoreSourcesComponent>;

beforeEach(() => {
  open.mockReset();
  navigation.layer.mockReset();
  navigation.openAlone.mockReset();
});

function mount(
  item: ListItem,
  reader: Partial<LinkReaderNavigation> | null = navigation,
  storyActions: Provider[] = [],
): HTMLElement {
  TestBed.configureTestingModule({
    imports: [MoreSourcesComponent, provideTranslocoTesting()],
    providers: [
      ...storyActions,
      provideStoryContext(storyOfItem(item)).provider,
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: { open } },
      ...(reader ? [{ provide: LinkReaderNavigation, useValue: reader }] : []),
      provideRouter([]),
    ],
  });
  fixture = TestBed.createComponent(MoreSourcesComponent);
  fixture.componentRef.setInput('entry', leadOf(item));
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

function expand(element: HTMLElement): void {
  element.querySelector<HTMLButtonElement>('.toggle')!.click();
  fixture.detectChanges();
}

const storyOf = (others: StoryArticleDto[]): ListItem =>
  aStory({
    outletCount: others.length + 1,
    viewed: false,
    shareCount: 0,
    articles: [aStoryArticle(), ...others],
  });

const copies = [
  aStoryArticle({
    id: 2,
    source: 'NDR',
    title: 'Storm hits the coast',
    imageUrl: 'https://img/2.jpg',
  }),
  aStoryArticle({ id: 3, source: 'taz', title: 'Coast braces for storm', imageUrl: null }),
];

it('renders nothing without other sources', () => {
  expect(mount(anEntry()).querySelector('.more-sources')).toBeNull();
});

it('keeps a post\'s own copies in their "Also in" pills, not mini cards', () => {
  const element = mount(anEntry({ duplicates: copies }));

  expect(element.querySelector('.more-sources')).toBeNull();
  expect(element.querySelector('app-entry-duplicates .lead')!.textContent).toContain('Also in');
  expect(element.querySelectorAll('app-entry-duplicates .also-entry').length).toBe(2);
});

it("opens a story's other outlets as mini cards, not pills", () => {
  const element = mount(storyOf(copies));

  expect(element.querySelector('.more-sources')).not.toBeNull();
  expect(element.querySelector('app-entry-duplicates')).toBeNull();
});

describe("a Trending story's line (#2128)", () => {
  const lineOf = (element: HTMLElement) => element.querySelector('.more-sources .story-line')!;
  const onTrending = (item: ListItem) => mount(item, navigation, provideStoryActions());

  it('holds the story actions first, the counts beside them and the toggle last', () => {
    const line = lineOf(onTrending(storyOf(copies)));

    expect([...line.children].map((child) => child.localName)).toEqual([
      'app-story-actions',
      'span',
      'button',
    ]);
    expect(line.children[1].classList.contains('counts')).toBe(true);
    expect(line.querySelector('app-story-actions .story-actions')).not.toBeNull();
    expect(line.lastElementChild!.classList.contains('toggle')).toBe(true);
  });

  it('names the story counts between the actions and the toggle (#2291)', () => {
    const story = aStory({ outletCount: 7, viewed: false, shareCount: 2 });
    const counts = lineOf(onTrending(story)).querySelector('.counts')!;

    expect(counts.textContent).toContain('7 sources');
    expect(counts.textContent).toContain('2 shares');
  });

  it('marks the toggle with the Trending icon', () => {
    const toggle = lineOf(onTrending(storyOf(copies))).querySelector('.toggle')!;

    expect(toggle.querySelector('app-icon')!.textContent!.trim()).toBe('trending_up');
  });

  it('still shows for a story with no other articles, holding only its actions', () => {
    const line = lineOf(onTrending(storyOf([])));

    expect(line.querySelector('.toggle')).toBeNull();
    expect(line.querySelectorAll('.story-actions button').length).toBe(2);
  });

  it('offers no story actions off Trending', () => {
    const element = mount(storyOf(copies), navigation, provideStoryActions('all'));

    expect(element.querySelector('.story-actions')).toBeNull();
  });
});

it('counts the other sources on one collapsed line', () => {
  const element = mount(storyOf(copies));

  const toggle = element.querySelector('.toggle')!;
  expect(toggle.textContent).toContain('2');
  expect(toggle.getAttribute('aria-expanded')).toBe('false');
  expect(element.querySelector('.mini')).toBeNull();
});

it("counts a story's other articles, not its sources, on the toggle", () => {
  expect(mount(storyOf(copies)).querySelector('.toggle')!.textContent).toContain('2 more');
});

it('counts a single other article too', () => {
  expect(mount(storyOf([copies[0]])).querySelector('.toggle')!.textContent).toContain('1 more');
});

it('shows an article no followed outlet carries as the card its posts share', () => {
  const sharedArticle = aStoryArticle({
    id: 4,
    titleDerived: true,
    excerpt: 'wow, look at this',
    imageUrl: null,
    linkCards: [
      aLinkCard({
        title: 'Keepers strike at dawn',
        description: 'The lighthouse keepers walked out.',
        imageUrl: 'https://img/card.jpg',
      }),
    ],
    shares: [anEntry({ id: 9 })],
  });
  const element = mount(storyOf([sharedArticle]));
  expand(element);

  const mini = element.querySelector('.mini')!;
  expect(mini.querySelector('.title')!.textContent).toContain('Keepers strike at dawn');
  expect(mini.querySelector('.dek')!.textContent).toContain('The lighthouse keepers walked out.');
  expect(mini.querySelector('img.thumb')!.getAttribute('src')).toBe('https://img/card.jpg');
});

it('expands to one mini card per source, naming its feed and title', () => {
  const element = mount(storyOf(copies));
  expand(element);

  const minis = element.querySelectorAll('.mini');
  expect(minis.length).toBe(2);
  expect(minis[0].querySelector('.feed')!.textContent).toContain('NDR');
  expect(minis[0].querySelector('.title')!.textContent).toContain('Storm hits the coast');
  expect(element.querySelector('.toggle')!.getAttribute('aria-expanded')).toBe('true');
});

it('names a copy by its poster when someone other than the feed posted it', () => {
  const post = aStoryArticle({
    id: 4,
    source: 'Home',
    poster: { name: 'Hank Green', url: null, imageUrl: null },
  });
  const element = mount(storyOf([post]));
  expand(element);

  expect(element.querySelector('.mini .feed')!.textContent).toContain('Hank Green');
});

it("shows a source's picture as its thumbnail, its favicon when it has none", () => {
  const element = mount(storyOf(copies));
  expand(element);

  const [withImage, withoutImage] = Array.from(element.querySelectorAll('.mini'));
  expect(withImage.querySelector('img.thumb')!.getAttribute('src')).toBe('https://img/2.jpg');
  expect(withoutImage.querySelector('img.thumb')).toBeNull();
  expect(withoutImage.querySelector('.thumb app-favicon')).not.toBeNull();
});

it("loads a source's picture asynchronously and without a referrer", () => {
  const element = mount(storyOf(copies));
  expand(element);

  const thumb = element.querySelector('img.thumb')!;
  expect(thumb.getAttribute('referrerpolicy')).toBe('no-referrer');
  expect(thumb.getAttribute('decoding')).toBe('async');
});

const withRenditions = aStoryArticle({
  id: 7,
  source: 'BBC',
  title: 'Raman wins',
  imageUrl: 'https://img/7.jpg',
  imageRenditions: [{ url: 'https://img/7-240.jpg', width: 240 }],
});

function breakThumb(element: HTMLElement, index: number): void {
  element
    .querySelectorAll('.mini')
    [index].querySelector('img.thumb')!
    .dispatchEvent(new Event('error'));
  fixture.detectChanges();
}

it("loads the smallest rendition that covers a mini card's picture", () => {
  const element = mount(storyOf([withRenditions]));
  expand(element);

  const thumb = element.querySelector('img.thumb')!;
  expect(thumb.getAttribute('srcset')).toContain('https://img/7-240.jpg 240w');
  expect(thumb.getAttribute('sizes')).toBe('104px');
});

it('retries the plain picture when its rendition fails to load', () => {
  const element = mount(storyOf([withRenditions]));
  expand(element);
  expect(element.querySelector('img.thumb')!.getAttribute('srcset')).not.toBeNull();

  breakThumb(element, 0);

  const thumb = element.querySelector('img.thumb')!;
  expect(thumb.getAttribute('srcset')).toBeNull();
  expect(thumb.getAttribute('src')).toBe('https://img/7.jpg');
});

it('shows the favicon box on the one card whose picture fails to load', () => {
  const element = mount(storyOf([copies[0], withRenditions]));
  expand(element);

  breakThumb(element, 0);

  const [broken, intact] = Array.from(element.querySelectorAll('.mini'));
  expect(broken.querySelector('img.thumb')).toBeNull();
  expect(broken.querySelector('.thumb app-favicon')).not.toBeNull();
  expect(intact.querySelector('img.thumb')).not.toBeNull();
});

it('collapses again on a second click', () => {
  const element = mount(storyOf(copies));
  expand(element);
  expand(element);

  expect(element.querySelector('.mini')).toBeNull();
});

/** Mounts the footer inside a card that records whether an event of `type` reached it. */
function mountOnCard(type: 'click' | 'keydown'): { element: HTMLElement; reached: jest.Mock } {
  return mountOnCardWith(type, storyOf(copies));
}

function mountOnCardWith(
  type: 'click' | 'keydown',
  item: ListItem,
): { element: HTMLElement; reached: jest.Mock } {
  const card = document.createElement('div');
  const reached = jest.fn();
  card.addEventListener(type, reached);
  const element = mount(item);
  card.appendChild(element);
  return { element, reached };
}

it('opens the source a mini card shows, and not the card it sits on', () => {
  const { element, reached } = mountOnCard('click');
  expand(element);
  reached.mockReset();

  element.querySelectorAll<HTMLButtonElement>('.mini .face')[1].click();

  expect(open).toHaveBeenCalledWith(copies[1]);
  expect(reached).not.toHaveBeenCalled();
});

it("keeps the toggle's click from opening the card it sits on", () => {
  const { element, reached } = mountOnCard('click');

  expand(element);

  expect(reached).not.toHaveBeenCalled();
});

it.each([
  ['.toggle', 'Enter'],
  ['.toggle', ' '],
  ['.mini .face', 'Enter'],
  ['.mini .face', ' '],
])('keeps a "%s" key "%s" from reaching the card', (selector, key) => {
  const { element, reached } = mountOnCard('keydown');
  expand(element);

  element
    .querySelector(selector)!
    .dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

  expect(reached).not.toHaveBeenCalled();
});

it("shows each source's dek under its title", () => {
  const withDek = aStoryArticle({
    id: 5,
    source: 'BBC',
    title: 'Raman wins',
    excerpt: 'A second big win.',
  });
  const element = mount(storyOf([withDek]));
  expand(element);

  expect(element.querySelector('.mini .dek')!.textContent).toContain('A second big win.');
});

it('leaves out the dek line when a source has none', () => {
  const bare = aStoryArticle({ id: 6, source: 'CBC', title: 'New mayor', excerpt: '' });
  const element = mount(storyOf([bare]));
  expand(element);

  expect(element.querySelector('.mini .dek')).toBeNull();
});

it("puts the source's favicon in its byline", () => {
  const element = mount(storyOf(copies));
  expand(element);

  expect(element.querySelector('.mini .byline app-favicon')).not.toBeNull();
});

const poster = (id: number, name: string): EntryDto =>
  anEntry({
    id,
    titleDerived: true,
    excerpt: `${name} on the ruling`,
    poster: { name, url: null, imageUrl: null },
  });

const shareLed = (card: Parameters<typeof aLinkCard>[0], shares: EntryDto[]): StoryArticleDto =>
  aStoryArticle({
    id: 8,
    titleDerived: true,
    excerpt: 'look at this',
    poster: { name: 'CharlesDarwin', url: null, imageUrl: null },
    linkCards: [aLinkCard(card)],
    shares,
  });

it("shows a pill for each account that shared a mini card's article, apart from its link", () => {
  const element = mount(
    storyOf([shareLed({}, [poster(10, 'CharlesDarwin'), poster(11, 'Rosalind')])]),
  );
  expand(element);

  const mini = element.querySelector('.mini')!;
  expect(mini.querySelector('.face app-story-sharers')).toBeNull();
  const pills = Array.from(mini.querySelectorAll('app-story-sharers .sharer .name'));
  expect(pills.map((pill) => pill.textContent!.trim())).toEqual(['CharlesDarwin', 'Rosalind']);
});

it('opens the post a sharer pill stands for, and not the card it sits on', () => {
  const second = poster(11, 'Rosalind');
  const { element, reached } = mountOnCardWith(
    'click',
    storyOf([shareLed({}, [poster(10, 'CharlesDarwin'), second])]),
  );
  expand(element);
  reached.mockReset();

  element.querySelectorAll<HTMLButtonElement>('.mini app-story-sharers .sharer')[1].click();

  expect(open).toHaveBeenCalledWith(second);
  expect(reached).not.toHaveBeenCalled();
});

it('shows sharer pills on an outlet mini card posts share too', () => {
  const shared = aStoryArticle({ id: 12, source: 'NDR', shares: [poster(10, 'Ann')] });
  const element = mount(storyOf([shared]));
  expand(element);

  expect(element.querySelector('.mini app-story-sharers .sharer')!.textContent).toContain('Ann');
});

it('leaves out the sharer pills on a mini card no post shares', () => {
  const element = mount(storyOf(copies));
  expand(element);

  expect(element.querySelector('.mini app-story-sharers .sharers')).toBeNull();
});

const cardUrl = 'https://edition.cnn.com/2026/11/14/politics/judge';

it.each([
  ['.mini a.face', 'Enter'],
  ['.mini a.face', ' '],
  ['.mini', 'Enter'],
  ['.mini', ' '],
])('keeps a share-led "%s" key "%s" from reaching the card', (selector, key) => {
  const { element, reached } = mountOnCardWith(
    'keydown',
    storyOf([shareLed({ url: cardUrl }, [poster(10, 'Ann')])]),
  );
  expand(element);

  element
    .querySelector(selector)!
    .dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

  expect(reached).not.toHaveBeenCalled();
});

it.each([
  ['a share-led', storyOf([shareLed({ url: cardUrl }, [poster(10, 'Ann')])])],
  ['an outlet', storyOf(copies)],
])("keeps a click on %s mini card's padding from opening the card it sits on", (_, story) => {
  const { element, reached } = mountOnCardWith('click', story);
  expand(element);
  reached.mockReset();

  element.querySelector<HTMLElement>('.mini')!.click();

  expect(open).not.toHaveBeenCalled();
  expect(reached).not.toHaveBeenCalled();
});

it('marks the reader overlay available only where the reader can open', () => {
  const story = storyOf([shareLed({ url: cardUrl }, [poster(10, 'Ann')])]);
  const withReader = mount(story);
  expand(withReader);
  expect(withReader.querySelector('.mini > app-open-in-reader.available')).not.toBeNull();

  TestBed.resetTestingModule();
  const withoutReader = mount(story, null);
  expand(withoutReader);
  expect(withoutReader.querySelector('.mini > app-open-in-reader.available')).toBeNull();
  expect(withoutReader.querySelector('.mini button.open-in-reader')).toBeNull();
});

it("shows no thumb box on a share-led mini card without a picture, never the sharer's icon", () => {
  const element = mount(storyOf([shareLed({ url: cardUrl, imageUrl: null }, [poster(10, 'Ann')])]));
  expand(element);

  expect(element.querySelector('.mini .thumb')).toBeNull();
});

it('drops a broken picture on a share-led mini card without a thumb box', () => {
  const element = mount(
    storyOf([shareLed({ url: cardUrl, imageUrl: 'https://img/card.jpg' }, [poster(10, 'Ann')])]),
  );
  expand(element);

  breakThumb(element, 0);

  expect(element.querySelector('.mini .thumb')).toBeNull();
});

it('links a share-led mini card to the real article, in a new tab', () => {
  const element = mount(storyOf([shareLed({ url: cardUrl }, [poster(10, 'Ann')])]));
  expand(element);

  const face = element.querySelector<HTMLAnchorElement>('.mini a.face')!;
  expect(face.getAttribute('href')).toBe(cardUrl);
  expect(face.getAttribute('target')).toBe('_blank');
  expect(face.getAttribute('rel')).toBe('noopener noreferrer');
});

it("keeps a click on a share-led mini card's link from opening the card it sits on", () => {
  const { element, reached } = mountOnCardWith(
    'click',
    storyOf([shareLed({ url: cardUrl }, [poster(10, 'Ann')])]),
  );
  expand(element);
  reached.mockReset();
  const face = element.querySelector<HTMLAnchorElement>('.mini a.face')!;
  face.addEventListener('click', (event) => event.preventDefault());

  face.click();

  expect(open).not.toHaveBeenCalled();
  expect(reached).not.toHaveBeenCalled();
});

it("opens a share-led mini card's article in the reader from its overlay", () => {
  const { element, reached } = mountOnCardWith(
    'click',
    storyOf([shareLed({ url: cardUrl }, [poster(10, 'Ann')])]),
  );
  expand(element);
  reached.mockReset();

  element.querySelector<HTMLButtonElement>('.mini button.open-in-reader')!.click();

  expect(navigation.openAlone).toHaveBeenCalledWith(expect.objectContaining({ url: cardUrl }));
  expect(element.querySelector('.mini .face button.open-in-reader')).toBeNull();
  expect(reached).not.toHaveBeenCalled();
});

it('keeps an outlet mini card opening its entry, with no reader overlay', () => {
  const element = mount(storyOf(copies));
  expand(element);

  expect(element.querySelector('.mini button.face')).not.toBeNull();
  expect(element.querySelector('.mini button.open-in-reader')).toBeNull();
});

it('counts no shares in the byline, which the sharers line already does', () => {
  const element = mount(storyOf([shareLed({}, [poster(10, 'Ann'), poster(11, 'Ben')])]));
  expand(element);

  expect(element.querySelector('.mini .byline')!.textContent).not.toContain('share');
});

it("names a share-led mini card by its article's site, not the post that shared it", () => {
  const element = mount(storyOf([shareLed({ siteName: 'CNN' }, [poster(10, 'CharlesDarwin')])]));
  expand(element);

  expect(element.querySelector('.mini .feed')!.textContent!.trim()).toBe('CNN');
});

it('names a share-led mini card by its host when the card has no site name', () => {
  const element = mount(
    storyOf([
      shareLed({ siteName: '', url: 'https://edition.cnn.com/2026/11/14/politics/judge' }, [
        poster(10, 'CharlesDarwin'),
      ]),
    ]),
  );
  expand(element);

  expect(element.querySelector('.mini .feed')!.textContent!.trim()).toBe('edition.cnn.com');
});

it("names a share-led mini card by its host without the host's www.", () => {
  const element = mount(
    storyOf([shareLed({ siteName: '', url: 'https://www.cnn.com/a' }, [poster(10, 'Ann')])]),
  );
  expand(element);

  expect(element.querySelector('.mini .feed')!.textContent!.trim()).toBe('cnn.com');
});

it('keeps a dek that merely mentions a URL', () => {
  const element = mount(
    storyOf([shareLed({ description: 'Read more at https://cnn.com today' }, [poster(10, 'Ann')])]),
  );
  expand(element);

  expect(element.querySelector('.mini .dek')!.textContent).toContain('Read more at');
});

it('leads a link post with a title of its own by the card it shares, as a Lemmy link post', () => {
  const linkPost = aStoryArticle({
    id: 13,
    titleDerived: false,
    title: 'Judge halts plan',
    excerpt: cardUrl,
    poster: { name: 'CharlesDarwin', url: null, imageUrl: null },
    linkCards: [aLinkCard({ url: cardUrl, siteName: 'CNN', description: null })],
    shares: [poster(10, 'CharlesDarwin')],
  });
  const element = mount(storyOf([linkPost]));
  expand(element);

  expect(element.querySelector('.mini a.face')!.getAttribute('href')).toBe(cardUrl);
  expect(element.querySelector('.mini .feed')!.textContent!.trim()).toBe('CNN');
  expect(element.querySelector('.mini .dek')).toBeNull();
});
