import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../../testing/transloco-testing';
import { EntryLinkComponent } from './entry-link.component';
import { EntryDto } from '../../../../models';
import { LinkCardDto } from '../../../../link-card/link-card';
import { EntryActionHandler } from '../../../../entry/entry-actions/entry-action-handler';
import { PreviewProgress } from '../../../../link-card/preview-progress';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';

const entryActions = {
  favorite: jest.fn(),
  keep: jest.fn(),
  toggleRead: jest.fn(),
  open: jest.fn(),
};

beforeEach(() => {
  Object.values(entryActions).forEach((spy) => spy.mockReset());
});

const card = (over: Partial<LinkCardDto> = {}): LinkCardDto => ({
  url: 'https://news.test/story',
  title: 'A shared story',
  description: 'What the story is about.',
  imageUrl: 'https://news.test/story.jpg',
  siteName: 'news.test',
  ...over,
});

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Worth a read',
  url: 'https://social.test/@someone/1',
  author: null,
  summary: null,
  excerpt: 'Worth a read, this one.',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
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
  isShort: false,
  titleDerived: true,
  share: 'link',
  linkCards: [card(), card({ url: 'https://other.test/', title: 'A second story' })],
  pending: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
  ...over,
});

function mount(testEntry: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryLinkComponent, provideTranslocoTesting()],
    providers: [
      { provide: EntryActionHandler, useValue: entryActions },
      { provide: PreviewProgress, useValue: { isLoading: () => true } },
      provideRouter([]),
    ],
  });
  const fixture = TestBed.createComponent(EntryLinkComponent);
  fixture.componentRef.setInput('entry', testEntry);
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
    expect(element.querySelector('.link-card span')).toBeNull();
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
});
