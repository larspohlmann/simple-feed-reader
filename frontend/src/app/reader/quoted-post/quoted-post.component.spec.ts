import { TestBed } from '@angular/core/testing';
import { QuotedPostComponent, QuotedPostLayout } from './quoted-post.component';
import { QuotedPostDto } from './quoted-post';
import { ImageProxyService } from '../../shared/proxied-image/image-proxy.service';
import { aLinkCard, aQuotedPost } from '../../../testing/entry-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const failingProxy = { recover: () => Promise.resolve('failed' as const) };

beforeEach(() => {
  jest.useFakeTimers().setSystemTime(new Date('2026-10-14T03:07:00Z'));
});
afterEach(() => jest.useRealTimers());

function mount(overrides: Partial<QuotedPostDto> = {}, layout: QuotedPostLayout = 'block') {
  TestBed.configureTestingModule({
    imports: [QuotedPostComponent, provideTranslocoTesting()],
    providers: [{ provide: ImageProxyService, useValue: failingProxy }],
  });
  const fixture = TestBed.createComponent(QuotedPostComponent);
  fixture.componentRef.setInput('quote', aQuotedPost(overrides));
  fixture.componentRef.setInput('layout', layout);
  fixture.detectChanges();
  return fixture;
}

const query = (fixture: { nativeElement: unknown }, selector: string) =>
  (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(selector);

describe('QuotedPostComponent', () => {
  it('shows the author and the narrow relative time in the block layout', () => {
    const fixture = mount({
      authorName: 'Quoted Poster',
      authorHandle: 'quoted.bsky.social',
      postedAt: '2026-10-13T21:07:00Z',
    });

    expect(query(fixture, '.name')!.textContent).toBe('Quoted Poster');
    expect(query(fixture, '.handle')!.textContent).toBe('@quoted.bsky.social');
    const time = query(fixture, 'time')!;
    expect(time.getAttribute('datetime')).toBe('2026-10-13T21:07:00Z');
    expect(time.textContent).toBe('6h ago');
  });

  it('shows the long date and time in the article layout', () => {
    const fixture = mount({ postedAt: '2026-10-13T21:07:00Z' }, 'article');

    expect(query(fixture, 'time')!.textContent).toContain('2026');
    expect(query(fixture, 'time')!.textContent).not.toContain('ago');
  });

  it('shows no time for an undated post', () => {
    expect(query(mount({ postedAt: null }), 'time')).toBeNull();
  });

  it('keeps the text line breaks', () => {
    const text = query(mount({ text: 'one\n\ntwo' }), '.text')!;

    expect(text.textContent).toContain('\n');
  });

  it('opens the quoted post in a new tab, without reaching a parent click', () => {
    const fixture = mount({ url: 'https://bsky.app/profile/q/post/1' });
    const parentClick = jest.fn();
    (fixture.nativeElement as HTMLElement).addEventListener('click', parentClick);
    const open = query(fixture, 'a.open') as HTMLAnchorElement;

    open.click();

    expect(open.getAttribute('href')).toBe('https://bsky.app/profile/q/post/1');
    expect(open.target).toBe('_blank');
    expect(open.rel).toContain('noopener');
    expect(parentClick).not.toHaveBeenCalled();
  });

  it('shows the quoted post’s link card inside the box, in the quote’s layout', () => {
    const fixture = mount(
      { linkCard: aLinkCard({ title: 'A shared story', siteName: 'news.test' }) },
      'article',
    );

    const card = query(fixture, '.box app-link-card')!;
    expect(card.classList).toContain('article');
    expect(card.querySelector('strong')!.textContent).toBe('A shared story');
    expect(card.querySelector('a')!.getAttribute('href')).toBe('https://news.test/story');
  });

  it('puts the card in the quote layout in the magazine', () => {
    expect(query(mount({ linkCard: aLinkCard() }), 'app-link-card')!.classList).toContain('quote');
  });

  it('opens the card’s page without reaching a parent click', () => {
    const fixture = mount({ linkCard: aLinkCard() });
    const parentClick = jest.fn();
    (fixture.nativeElement as HTMLElement).addEventListener('click', parentClick);
    const link = query(fixture, 'app-link-card a') as HTMLAnchorElement;
    link.addEventListener('click', (event) => event.preventDefault());

    link.click();

    expect(link.target).toBe('_blank');
    expect(parentClick).not.toHaveBeenCalled();
  });

  it.each([
    { name: 'Enter', key: 'Enter' },
    { name: 'Space', key: ' ' },
  ])('keeps $name on the card from reaching a parent', ({ key }) => {
    const fixture = mount({ linkCard: aLinkCard() });
    const parentKeydown = jest.fn();
    (fixture.nativeElement as HTMLElement).addEventListener('keydown', parentKeydown);

    query(fixture, 'app-link-card a')!.dispatchEvent(
      new KeyboardEvent('keydown', { key, bubbles: true }),
    );

    expect(parentKeydown).not.toHaveBeenCalled();
  });

  it('renders no card without one', () => {
    expect(query(mount({ linkCard: null }), 'app-link-card')).toBeNull();
  });

  it('swaps a failed avatar for the author initials', async () => {
    const fixture = mount({
      authorName: 'Quoted Poster',
      authorAvatarUrl: 'https://cdn.test/a.jpg',
    });
    expect(query(fixture, 'img.avatar')).not.toBeNull();

    query(fixture, 'img.avatar')!.dispatchEvent(new Event('error'));
    await Promise.resolve();
    fixture.detectChanges();

    expect(query(fixture, 'img.avatar')).toBeNull();
    expect(query(fixture, '.avatar.initials')!.textContent).toBe('QP');
  });

  it('shows the initials straight away when the author has no avatar', () => {
    const fixture = mount({ authorName: 'Quoted Poster', authorAvatarUrl: null });

    expect(query(fixture, '.avatar.initials')!.textContent).toBe('QP');
  });

  it('colours the initials by author handle, the same each time', () => {
    const tone = (handle: string) =>
      query(mount({ authorHandle: handle }), '.avatar.initials')!.className.match(/tone-\d/)![0];
    const first = tone('a');
    TestBed.resetTestingModule();

    expect(tone('a')).toBe(first);
    TestBed.resetTestingModule();
    expect(tone('b')).not.toBe(first);
  });

  it('names the handle of a nested quote', () => {
    const fixture = mount({ nestedQuote: { url: 'https://n/1', handle: 'n.bsky.social' } });

    const nested = query(fixture, 'a.nested')!;
    expect(nested.textContent!.trim()).toBe('Quoting a post by @n.bsky.social');
    expect(nested.getAttribute('href')).toBe('https://n/1');
  });

  it('reads a nested quote of unknown author without a handle', () => {
    const fixture = mount({ nestedQuote: { url: 'https://n/1', handle: null } });

    expect(query(fixture, 'a.nested')!.textContent!.trim()).toBe('Quoting a post');
  });

  it('renders no nested line without a nested quote', () => {
    expect(query(mount({ nestedQuote: null }), 'a.nested')).toBeNull();
  });

  it('hands its media to the media grid', () => {
    const media = [
      { url: 'https://cdn.test/1.jpg', kind: 'image' as const, width: 800, height: 600 },
      { url: 'https://cdn.test/2.jpg', kind: 'image' as const, width: 800, height: 600 },
    ];

    expect(query(mount({ media }), 'app-post-media')!.dataset['count']).toBe('2');
  });

  it('shapes the media grid after the first medium, or 16:9 when its size is unknown', () => {
    const sized = {
      url: 'https://cdn.test/1.jpg',
      kind: 'image' as const,
      width: 800,
      height: 400,
    };
    const unsized = { url: 'https://cdn.test/2.jpg', kind: 'image' as const };

    expect(
      query(mount({ media: [sized] }), 'app-post-media')!.style.getPropertyValue('--ratio'),
    ).toBe('2');
    TestBed.resetTestingModule();
    expect(
      query(mount({ media: [unsized] }), 'app-post-media')!.style.getPropertyValue('--ratio'),
    ).toBe(String(16 / 9));
  });

  it('renders no media grid for a text-only post', () => {
    expect(query(mount({ media: [] }), 'app-post-media')).toBeNull();
  });

  it.each<QuotedPostLayout>(['block', 'article'])('marks its host with the %s layout', (layout) => {
    const fixture = mount({}, layout);

    expect((fixture.nativeElement as HTMLElement).classList.contains(layout)).toBe(true);
  });
});
