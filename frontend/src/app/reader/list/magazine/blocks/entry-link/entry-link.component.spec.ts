import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryLinkComponent } from './entry-link.component';
import { EntryDto } from '../../../../models';
import { ListItem, leadOf, storyOf } from '../../../../state/list-item';
import { StoryArticleDto } from '../../../../trending/trending.models';
import { LinkCardDto } from '../../../../link-card/link-card';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { LinkReaderNavigation } from '../../../../shell/link-reader-navigation.service';
import { PreviewProgress } from '../../../../link-card/preview-progress';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { aLinkCard, anEntry, aStory, aStoryArticle } from '../../../../../../testing/entry-testing';
import { provideAccountIdentity } from '../../../../../../testing/account-identity-testing';
import { provideStoryContext } from '../../../../../../testing/story-context-testing';
import { provideSocialAccounts } from '../../../../../../testing/social-accounts-testing';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

const navigation = { layer: jest.fn(), openAlone: jest.fn() };

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
  Object.values(navigation).forEach((spy) => spy.mockReset());
});

const card = (over: Partial<LinkCardDto> = {}): LinkCardDto =>
  aLinkCard({
    description: 'What the story is about.',
    imageUrl: 'https://news.test/story.jpg',
    ...over,
  });

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    url: 'https://social.test/@someone/1',
    excerpt: 'Worth a read, this one.',
    titleDerived: true,
    share: 'link',
    linkCards: [card(), card({ url: 'https://other.test/', title: 'A second story' })],
    ...over,
  });

const article = (over: Partial<StoryArticleDto> = {}): StoryArticleDto =>
  aStoryArticle({ ...entry(), ...over });

function mount(item: ListItem) {
  TestBed.configureTestingModule({
    imports: [EntryLinkComponent, provideTranslocoTesting()],
    providers: [
      provideStoryContext(storyOf(item)).provider,
      provideSocialAccounts(),
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: entryActions },
      { provide: LinkReaderNavigation, useValue: navigation },
      { provide: PreviewProgress, useValue: { isLoading: () => true } },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(EntryLinkComponent);
  fixture.componentRef.setInput('entry', leadOf(item));
  fixture.detectChanges();
  return fixture;
}

describe('EntryLinkComponent', () => {
  it('shows a titled share’s headline as a headline, its own text beneath, then the card', () => {
    const element = mount(
      entry({
        titleDerived: false,
        title: 'C for Rust programmers',
        excerpt: 'Worth a read.',
        linkCards: [card()],
      }),
    ).nativeElement as HTMLElement;

    const title = element.querySelector('.title')!;
    expect(title.textContent).toContain('C for Rust programmers');
    expect(title.classList.contains('post')).toBe(false);
    expect(element.querySelector('.dek')!.textContent).toContain('Worth a read.');
    expect(element.querySelector('app-link-card')).not.toBeNull();
  });

  it('shows no dek for a post: its text is already its headline', () => {
    const element = mount(entry({ excerpt: 'Post text', linkCards: [card()] }))
      .nativeElement as HTMLElement;

    const title = element.querySelector('.title')!;
    expect(title.classList.contains('post')).toBe(true);
    expect(title.textContent).toContain('Post text');
    expect(element.querySelector('.dek')).toBeNull();
  });

  it('drops the link a post ends in when its card shows it', () => {
    const element = mount(entry({ excerpt: 'Worth a read https://news.test/story' }))
      .nativeElement as HTMLElement;

    expect(element.querySelector('.title')!.textContent!.trim()).toBe('Worth a read');
  });

  it('drops a link inside a post’s text when its card shows it', () => {
    const element = mount(entry({ excerpt: 'Read https://news.test/story #News' }))
      .nativeElement as HTMLElement;

    expect(element.querySelector('.title')!.textContent!.trim()).toBe('Read #News');
  });

  it('shows no headline for a post that is only its link: the card heads the block', () => {
    const element = mount(entry({ excerpt: 'https://news.test/story' }))
      .nativeElement as HTMLElement;

    expect(element.querySelector('.title')).toBeNull();
    expect(element.querySelector('app-link-card')).not.toBeNull();
  });

  it('drops the link a titled share’s text ends in when its card shows it', () => {
    const element = mount(
      entry({ titleDerived: false, title: 'A headline', excerpt: 'Summary politi.co/4kVc0CX' }),
    ).nativeElement as HTMLElement;

    expect(element.querySelector('.dek')!.textContent!.trim()).toBe('Summary');
  });

  it('keeps the link while the card is still being completed', () => {
    const element = mount(
      entry({ excerpt: 'Worth a read https://news.test/story', linkCards: [], pending: true }),
    ).nativeElement as HTMLElement;

    expect(element.querySelector('.title')!.textContent!.trim()).toBe(
      'Worth a read https://news.test/story',
    );
  });

  it('shows the post text as its headline and stacks the first card under it', () => {
    const element = mount(entry()).nativeElement as HTMLElement;

    expect(element.querySelector('.title')!.textContent).toContain('Worth a read, this one.');
    const cards = element.querySelectorAll<HTMLAnchorElement>('.link-card a');
    expect(cards).toHaveLength(1);
    expect(cards[0].href).toBe('https://news.test/story');
    expect(cards[0].target).toBe('_blank');
    expect(cards[0].rel).toBe('noopener noreferrer');
    expect(cards[0].querySelector('small')!.textContent).toContain('news.test');
    expect(cards[0].querySelector('strong')!.textContent).toContain('A shared story');
    expect(cards[0].querySelector('span')!.textContent).toContain('What the story is about.');
    const image = cards[0].querySelector('img')!;
    expect(image.getAttribute('src')).toBe('https://news.test/story.jpg');
    expect(image.getAttribute('loading')).toBe('lazy');
    expect(image.getAttribute('referrerpolicy')).toBe('no-referrer');
  });

  it('leaves out the picture and the description a card does not have', () => {
    const element = mount(entry({ linkCards: [card({ imageUrl: null, description: null })] }))
      .nativeElement as HTMLElement;

    expect(element.querySelector('.link-card img')).toBeNull();
    expect(element.querySelector('.link-card a span')).toBeNull();
  });

  it('drops a picture that fails for good, and shows the next card its own', () => {
    const fixture = mount(entry());
    const element = fixture.nativeElement as HTMLElement;
    const image = fixture.debugElement.query(By.directive(ProxiedImageDirective));

    image.injector.get(ProxiedImageDirective).imageFailed.emit();
    fixture.detectChanges();
    expect(element.querySelector('.link-card img')).toBeNull();

    fixture.componentRef.setInput(
      'entry',
      entry({ id: 2, linkCards: [card({ url: 'https://third.test/' })] }),
    );
    fixture.detectChanges();
    expect(element.querySelector('.link-card img')).not.toBeNull();
  });

  it('opens the shared page, not the post, when the card is clicked or keyed', () => {
    const element = mount(entry()).nativeElement as HTMLElement;
    const link = element.querySelector<HTMLAnchorElement>('.link-card a')!;
    link.addEventListener('click', (event) => event.preventDefault());

    link.click();
    link.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    link.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));

    expect(entryActions.open).not.toHaveBeenCalled();
  });

  it('opens the post from the rest of the block', () => {
    const element = mount(entry()).nativeElement as HTMLElement;

    element.querySelector<HTMLElement>('.title')!.click();

    expect(entryActions.open).toHaveBeenCalled();
  });

  it('carries the meta row', () => {
    const element = mount(entry()).nativeElement as HTMLElement;

    expect(element.querySelectorAll('app-entry-meta app-entry-actions button').length).toBe(3);
  });

  it('holds the card place with a placeholder while the post is still being completed', () => {
    const element = mount(entry({ linkCards: [], pending: true })).nativeElement as HTMLElement;

    expect(element.querySelector('.link-card a')).toBeNull();
    expect(element.querySelector('app-link-card-placeholder')!.textContent).toContain(
      'Loading preview…',
    );
  });

  it('shows the card, not the placeholder, once the post has one', () => {
    const element = mount(entry({ pending: true })).nativeElement as HTMLElement;

    expect(element.querySelector('.link-card a')).not.toBeNull();
    expect(element.querySelector('app-link-card-placeholder')).toBeNull();
  });

  it('opens the first card alone in the reader without opening the entry', () => {
    const testEntry = entry();
    const element = mount(testEntry).nativeElement as HTMLElement;

    element.querySelector<HTMLButtonElement>('button.open-in-reader')!.click();

    expect(navigation.openAlone).toHaveBeenCalledWith(testEntry.linkCards[0]);
    expect(entryActions.open).not.toHaveBeenCalled();
  });

  it('leads a Trending story with the post, its card, the sources line and the posters of the rest', () => {
    const other = (id: number, name: string): StoryArticleDto =>
      article({ id, source: 'Home@bsky', poster: { name, url: null, imageUrl: null } });
    const fixture = mount(
      aStory({
        outletCount: 4,
        viewed: false,
        shareCount: 0,
        articles: [
          article({ poster: { name: 'Ann', url: null, imageUrl: null }, linkCards: [card()] }),
          other(2, 'Ben'),
          other(3, 'Cat'),
          other(4, 'Dan'),
        ],
      }),
    );
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('.title')!.textContent).toContain('Worth a read, this one.');
    expect(element.querySelector('app-link-card strong')!.textContent).toContain('A shared story');
    expect(element.querySelector('app-link-card small')!.textContent).toContain('news.test');
    expect(element.querySelector('app-story-source-count')!.textContent).toContain('4 sources');
    element.querySelector<HTMLButtonElement>('app-more-sources .toggle')!.click();
    fixture.detectChanges();
    const feeds = [...element.querySelectorAll('app-more-sources .mini .feed')].map((feed) =>
      feed.textContent!.trim(),
    );
    expect(feeds).toEqual(['Ben', 'Cat', 'Dan']);
  });

  it('leads a story no followed outlet carries with the shared card, its sharers and the shares count', () => {
    const sharer = (id: number, name: string): EntryDto =>
      entry({ id, excerpt: `${name} says read this`, poster: { name, url: null, imageUrl: null } });
    const fixture = mount(
      aStory({
        outletCount: 2,
        viewed: false,
        shareCount: 3,
        articles: [
          article({
            poster: { name: 'Ann', url: null, imageUrl: null },
            linkCards: [card()],
            shares: [sharer(1, 'Ann'), sharer(5, 'Eve'), sharer(6, 'Fay')],
          }),
        ],
      }),
    );
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('.title')).toBeNull();
    expect(element.querySelector('app-link-card strong')!.textContent).toContain('A shared story');
    expect(element.querySelector('app-story-source-count')!.textContent).toContain('2 sources');
    expect(element.querySelector('app-story-source-count')!.textContent).toContain('3 shares');
    const sharers = element.querySelectorAll('app-story-sharers .sharer .name');
    expect(Array.from(sharers).map((sharer) => sharer.textContent!.trim())).toEqual([
      'Ann',
      'Eve',
      'Fay',
    ]);
  });

  describe('is led by its card only when the story in context shares one', () => {
    it('is led by the card for a story whose lead shares one', () => {
      const element = mount(
        aStory({
          outletCount: 2,
          viewed: false,
          shareCount: 3,
          articles: [
            article({
              poster: { name: 'Ann', url: null, imageUrl: null },
              linkCards: [card()],
              shares: [entry({ id: 1 })],
            }),
          ],
        }),
      ).nativeElement as HTMLElement;

      expect(element.querySelector('.title')).toBeNull();
    });

    it('keeps its own headline without a story context', () => {
      const element = mount(entry({ title: 'Own headline', titleDerived: false }))
        .nativeElement as HTMLElement;

      expect(element.querySelector('.title')!.textContent).toContain('Own headline');
    });
  });

  it('names no shares on a story no post shares', () => {
    const element = mount(
      aStory({ outletCount: 2, viewed: false, shareCount: 0, articles: [article()] }),
    ).nativeElement as HTMLElement;

    expect(element.querySelector('app-story-source-count')!.textContent).not.toContain('share');
    expect(element.querySelector('app-story-sharers .sharers')).toBeNull();
  });

  it('shows the sharers of the other articles on their mini cards', () => {
    const fixture = mount(
      aStory({
        outletCount: 3,
        viewed: false,
        shareCount: 2,
        articles: [
          article(),
          article({
            id: 2,
            titleDerived: false,
            title: 'Other article',
            shares: [
              entry({ id: 7, poster: { name: 'Gus', url: null, imageUrl: null } }),
              entry({ id: 8, poster: { name: 'Hal', url: null, imageUrl: null } }),
            ],
          }),
        ],
      }),
    );
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLButtonElement>('app-more-sources .toggle')!.click();
    fixture.detectChanges();

    const sharers = element.querySelectorAll(
      'app-more-sources .mini app-story-sharers .sharer .name',
    );
    expect(Array.from(sharers).map((sharer) => sharer.textContent!.trim())).toEqual(['Gus', 'Hal']);
  });
});
