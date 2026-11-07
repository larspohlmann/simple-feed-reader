import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SocialNetworkName } from '../../../core/social-network';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { SocialSearchFormComponent } from './social-search-form.component';
import { SearchPreview, SearchPreviewPost, SocialSearchService } from './social-search.service';

const failingProxy = { recover: () => Promise.resolve('failed' as const) };

function post(overrides: Partial<SearchPreviewPost> = {}): SearchPreviewPost {
  return {
    url: 'https://bsky.app/profile/amy/post/1',
    author: 'Amy',
    authorAvatarUrl: null,
    text: 'Looking for a good rss reader',
    publishedAt: '2026-11-07T10:00:00Z',
    ...overrides,
  };
}

const AUTHORS = ['Amy', 'Ben', 'Cy', 'Dee', 'Eve', 'Fay', 'Gus', 'Hal', 'Ivy', 'Jo'];

const TEN_POSTS: SearchPreview = {
  count: 42,
  posts: AUTHORS.map((author) => post({ author })),
};

describe('SocialSearchFormComponent', () => {
  const socialSearch = { preview: jest.fn<Observable<SearchPreview>, [number, string]>() };

  function mount(
    network: SocialNetworkName = 'bluesky',
    initialQuery = '',
  ): ComponentFixture<SocialSearchFormComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SocialSearchFormComponent, provideTranslocoTesting()],
      providers: [
        { provide: SocialSearchService, useValue: socialSearch },
        { provide: ImageProxyService, useValue: failingProxy },
      ],
    });
    const fixture = TestBed.createComponent(SocialSearchFormComponent);
    fixture.componentRef.setInput('network', network);
    fixture.componentRef.setInput('accountId', 4);
    fixture.componentRef.setInput('initialQuery', initialQuery);
    fixture.detectChanges();
    return fixture;
  }

  const byTestId = <T extends HTMLElement>(
    fixture: ComponentFixture<SocialSearchFormComponent>,
    testId: string,
  ): T | null =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(`[data-testid="${testId}"]`);

  const buttonOf = (
    fixture: ComponentFixture<SocialSearchFormComponent>,
    testId: string,
  ): HTMLButtonElement => byTestId(fixture, testId)?.querySelector('button') as HTMLButtonElement;

  function type(fixture: ComponentFixture<SocialSearchFormComponent>, text: string): void {
    const field = byTestId<HTMLTextAreaElement>(
      fixture,
      'social-search-query',
    ) as HTMLTextAreaElement;
    field.value = text;
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function keyDown(
    fixture: ComponentFixture<SocialSearchFormComponent>,
    init: KeyboardEventInit,
  ): KeyboardEvent {
    const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true, ...init });
    byTestId(fixture, 'social-search-query')?.dispatchEvent(event);
    return event;
  }

  function press(fixture: ComponentFixture<SocialSearchFormComponent>, testId: string): void {
    buttonOf(fixture, testId).click();
    fixture.detectChanges();
  }

  function previewed(
    answer: SearchPreview,
    network: SocialNetworkName = 'bluesky',
  ): ComponentFixture<SocialSearchFormComponent> {
    socialSearch.preview.mockReturnValue(of(answer));
    const fixture = mount(network);
    type(fixture, 'rss reader');
    press(fixture, 'social-search-preview');
    return fixture;
  }

  beforeEach(() => socialSearch.preview.mockReset());

  it('keeps Preview disabled until the query has more than whitespace', () => {
    const fixture = mount();
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(true);

    type(fixture, '   ');
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(true);

    type(fixture, 'rss');
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(false);
  });

  it('previews the trimmed query and shows the count and the posts it found', () => {
    socialSearch.preview.mockReturnValue(of(TEN_POSTS));
    const fixture = mount();
    type(fixture, '  "rss reader" -gazette  ');

    press(fixture, 'social-search-preview');

    expect(socialSearch.preview).toHaveBeenCalledWith(4, '"rss reader" -gazette');
    const result = byTestId(fixture, 'social-search-result') as HTMLElement;
    expect(result.textContent).toContain('42 recent posts match');
    const rows = Array.from(result.querySelectorAll('li'));
    expect(rows.map((row) => row.querySelector('.author')?.textContent?.trim())).toEqual(AUTHORS);
    const link = rows[0].querySelector('a') as HTMLAnchorElement;
    expect(link.href).toBe('https://bsky.app/profile/amy/post/1');
    expect(link.target).toBe('_blank');
  });

  it('says one post in the singular', () => {
    const fixture = previewed({ count: 1, posts: [post()] });
    expect(byTestId(fixture, 'social-search-result')?.textContent).toContain(
      '1 recent post matches',
    );
  });

  it('takes the query in a three-row field of at most 512 characters', () => {
    const field = byTestId<HTMLTextAreaElement>(mount(), 'social-search-query');

    expect(field?.tagName).toBe('TEXTAREA');
    expect(field?.rows).toBe(3);
    expect(field?.maxLength).toBe(512);
  });

  it.each<[string, KeyboardEventInit]>([
    ['Ctrl', { ctrlKey: true }],
    ['Cmd', { metaKey: true }],
  ])('previews on %s+Enter', (_modifier, init) => {
    socialSearch.preview.mockReturnValue(of(TEN_POSTS));
    const fixture = mount();
    type(fixture, 'rss');

    const event = keyDown(fixture, init);

    expect(socialSearch.preview).toHaveBeenCalledWith(4, 'rss');
    expect(event.defaultPrevented).toBe(true);
  });

  it('leaves Enter alone to start a new line', () => {
    const fixture = mount();
    type(fixture, 'rss');

    const event = keyDown(fixture, {});

    expect(socialSearch.preview).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
  });

  it('previews a query typed over several lines as one line', () => {
    socialSearch.preview.mockReturnValue(of(TEN_POSTS));
    const fixture = mount();
    type(fixture, '"rss reader"\n  -gazette\n');

    press(fixture, 'social-search-preview');

    expect(socialSearch.preview).toHaveBeenCalledWith(4, '"rss reader" -gazette');
    expect(fixture.componentInstance.normalizedQuery()).toBe('"rss reader" -gazette');
    type(fixture, '"rss reader" -gazette');
    expect(fixture.componentInstance.preview()).not.toBeNull();
  });

  it('shows placeholder rows while the preview runs and disables Preview', () => {
    const answer = new Subject<SearchPreview>();
    socialSearch.preview.mockReturnValue(answer);
    const fixture = mount();
    type(fixture, 'rss');

    press(fixture, 'social-search-preview');

    expect(byTestId(fixture, 'social-search-loading')).not.toBeNull();
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(true);
    answer.next(TEN_POSTS);
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-search-loading')).toBeNull();
  });

  it('warns that nothing matches yet, and still counts as previewed', () => {
    const fixture = previewed({ count: 0, posts: [] });

    const empty = byTestId(fixture, 'social-search-empty') as HTMLElement;
    expect(empty.textContent).toContain('No recent posts match.');
    expect(empty.textContent).not.toContain('full-text search');
    expect(fixture.componentInstance.preview()).not.toBeNull();
  });

  it('adds on Mastodon that the instance may not search full text', () => {
    const fixture = previewed({ count: 0, posts: [] }, 'mastodon');

    expect(byTestId(fixture, 'social-search-empty')?.textContent).toContain(
      'Your instance may not support full-text search.',
    );
  });

  it('shows the problem when the preview fails', () => {
    socialSearch.preview.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 502,
            error: { type: 'about:blank', title: 'Bluesky did not answer', status: 502 },
          }),
      ),
    );
    const fixture = mount();
    type(fixture, 'rss');

    press(fixture, 'social-search-preview');

    expect(byTestId(fixture, 'social-search-result')?.textContent).toContain(
      'Bluesky did not answer',
    );
    expect(fixture.componentInstance.preview()).toBeNull();
  });

  it('has a preview only of the query as it reads now', () => {
    socialSearch.preview.mockReturnValue(of(TEN_POSTS));
    const fixture = mount();
    type(fixture, ' rss reader ');
    expect(fixture.componentInstance.preview()).toBeNull();

    press(fixture, 'social-search-preview');
    expect(fixture.componentInstance.preview()).not.toBeNull();
    expect(fixture.componentInstance.normalizedQuery()).toBe('rss reader');

    type(fixture, 'rss readers');
    expect(fixture.componentInstance.preview()).toBeNull();
  });

  it('starts from the query it is given', () => {
    socialSearch.preview.mockReturnValue(of(TEN_POSTS));
    const fixture = mount('bluesky', 'rss -gazette');

    expect(byTestId<HTMLTextAreaElement>(fixture, 'social-search-query')?.value).toBe(
      'rss -gazette',
    );
    press(fixture, 'social-search-preview');
    expect(socialSearch.preview).toHaveBeenCalledWith(4, 'rss -gazette');
  });

  it('hides the result of a preview once the query is edited, and shows it again for that query', () => {
    const fixture = previewed(TEN_POSTS);
    const result = (): string => byTestId(fixture, 'social-search-result')?.textContent ?? '';
    expect(result()).toContain('42 recent posts match');

    type(fixture, 'rss readers');
    expect(result()).not.toContain('42 recent posts match');
    expect(result()).not.toContain('Amy');

    type(fixture, ' rss reader ');
    expect(result()).toContain('42 recent posts match');
  });

  it('hides the empty warning once the query is edited', () => {
    const fixture = previewed({ count: 0, posts: [] });

    type(fixture, 'atom');

    expect(byTestId(fixture, 'social-search-empty')).toBeNull();
  });

  it('hides a failed preview once the query is edited', () => {
    socialSearch.preview.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 502,
            error: { type: 'about:blank', title: 'Bluesky did not answer', status: 502 },
          }),
      ),
    );
    const fixture = mount();
    type(fixture, 'rss');
    press(fixture, 'social-search-preview');

    type(fixture, 'atom');

    expect(byTestId(fixture, 'social-search-result')?.textContent).not.toContain(
      'Bluesky did not answer',
    );
  });

  it('shows the combined Bluesky example on a line of its own, apart from the rules', () => {
    const fixture = mount('bluesky');
    const syntax = byTestId(fixture, 'social-search-syntax') as HTMLElement;

    expect(syntax.querySelector('.rules')?.textContent).not.toContain('rssreader');
    const combined = byTestId(fixture, 'social-search-combined') as HTMLElement;
    expect(combined.textContent).toContain('All of it combined:');
    expect(combined.querySelector('code')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      '("rss reader" || "feed reader" || rssreader) -gazette',
    );
    const terms = Array.from(combined.querySelectorAll('.term'), (term) => term.textContent);
    expect(terms).toContain('-gazette');
  });

  it.each<SocialNetworkName>(['bluesky', 'mastodon'])(
    'lists leaving out an account on %s',
    (network) => {
      const rules = byTestId(mount(network), 'social-search-syntax')?.querySelector('.rules');

      expect(rules?.textContent).toContain('-from:handle');
      expect(rules?.textContent).toContain('leaves out posts by this account');
    },
  );

  it('lists the either-or operator for Bluesky only', () => {
    const bluesky = mount('bluesky');
    expect(byTestId(bluesky, 'social-search-syntax')?.textContent).toContain('||');

    const mastodon = mount('mastodon');
    const syntax = byTestId(mastodon, 'social-search-syntax')?.textContent ?? '';
    expect(syntax).not.toContain('||');
    expect(syntax).toContain('full-text search');
  });
});
