import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SocialNetworkName } from '../../../core/social-network';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { SocialSearchTabComponent } from './social-search-tab.component';
import { SearchPreview, SearchPreviewPost, SocialImportService } from './social-import.service';

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

const FOUR_POSTS: SearchPreview = {
  count: 42,
  posts: [post(), post({ author: 'Ben' }), post({ author: 'Cy' }), post({ author: 'Dee' })],
};

describe('SocialSearchTabComponent', () => {
  const importer = {
    preview: jest.fn<Observable<SearchPreview>, [number, string]>(),
    importing: () => false,
  };

  function mount(
    network: SocialNetworkName = 'bluesky',
  ): ComponentFixture<SocialSearchTabComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SocialSearchTabComponent, provideTranslocoTesting()],
      providers: [
        { provide: SocialImportService, useValue: importer },
        { provide: ImageProxyService, useValue: failingProxy },
      ],
    });
    const fixture = TestBed.createComponent(SocialSearchTabComponent);
    fixture.componentRef.setInput('network', network);
    fixture.componentRef.setInput('accountId', 4);
    fixture.detectChanges();
    return fixture;
  }

  const byTestId = <T extends HTMLElement>(
    fixture: ComponentFixture<SocialSearchTabComponent>,
    testId: string,
  ): T | null =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(`[data-testid="${testId}"]`);

  const buttonOf = (
    fixture: ComponentFixture<SocialSearchTabComponent>,
    testId: string,
  ): HTMLButtonElement => byTestId(fixture, testId)?.querySelector('button') as HTMLButtonElement;

  function type(fixture: ComponentFixture<SocialSearchTabComponent>, text: string): void {
    const input = byTestId<HTMLInputElement>(fixture, 'social-search-query') as HTMLInputElement;
    input.value = text;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function press(fixture: ComponentFixture<SocialSearchTabComponent>, testId: string): void {
    buttonOf(fixture, testId).click();
    fixture.detectChanges();
  }

  function previewed(
    answer: SearchPreview,
    network: SocialNetworkName = 'bluesky',
  ): ComponentFixture<SocialSearchTabComponent> {
    importer.preview.mockReturnValue(of(answer));
    const fixture = mount(network);
    type(fixture, 'rss reader');
    press(fixture, 'social-search-preview');
    return fixture;
  }

  beforeEach(() => importer.preview.mockReset());

  it('keeps Preview disabled until the query has more than whitespace', () => {
    const fixture = mount();
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(true);

    type(fixture, '   ');
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(true);

    type(fixture, 'rss');
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(false);
  });

  it('previews the trimmed query and shows the count and the newest three posts', () => {
    importer.preview.mockReturnValue(of(FOUR_POSTS));
    const fixture = mount();
    type(fixture, '  "rss reader" -gazette  ');

    press(fixture, 'social-search-preview');

    expect(importer.preview).toHaveBeenCalledWith(4, '"rss reader" -gazette');
    const result = byTestId(fixture, 'social-search-result') as HTMLElement;
    expect(result.textContent).toContain('42 recent posts match');
    const rows = Array.from(result.querySelectorAll('li'));
    expect(rows.map((row) => row.querySelector('.author')?.textContent?.trim())).toEqual([
      'Amy',
      'Ben',
      'Cy',
    ]);
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

  it('previews on Enter in the field', () => {
    importer.preview.mockReturnValue(of(FOUR_POSTS));
    const fixture = mount();
    type(fixture, 'rss');

    byTestId(fixture, 'social-search-query')?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter' }),
    );

    expect(importer.preview).toHaveBeenCalledWith(4, 'rss');
  });

  it('shows placeholder rows while the preview runs and disables Preview', () => {
    const answer = new Subject<SearchPreview>();
    importer.preview.mockReturnValue(answer);
    const fixture = mount();
    type(fixture, 'rss');

    press(fixture, 'social-search-preview');

    expect(byTestId(fixture, 'social-search-loading')).not.toBeNull();
    expect(buttonOf(fixture, 'social-search-preview').disabled).toBe(true);
    answer.next(FOUR_POSTS);
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-search-loading')).toBeNull();
  });

  it('warns that nothing matches yet, without refusing the subscription', () => {
    const fixture = previewed({ count: 0, posts: [] });

    const empty = byTestId(fixture, 'social-search-empty') as HTMLElement;
    expect(empty.textContent).toContain('No recent posts match.');
    expect(empty.textContent).not.toContain('full-text search');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(false);
  });

  it('adds on Mastodon that the instance may not search full text', () => {
    const fixture = previewed({ count: 0, posts: [] }, 'mastodon');

    expect(byTestId(fixture, 'social-search-empty')?.textContent).toContain(
      'Your instance may not support full-text search.',
    );
  });

  it('shows the problem when the preview fails', () => {
    importer.preview.mockReturnValue(
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
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(true);
  });

  it('subscribes only to the query it has previewed', () => {
    importer.preview.mockReturnValue(of(FOUR_POSTS));
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.subscribe.subscribe((query) => emitted.push(query));
    type(fixture, ' rss reader ');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(true);

    press(fixture, 'social-search-preview');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(false);

    type(fixture, 'rss readers');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(true);

    type(fixture, 'rss reader');
    press(fixture, 'social-search-subscribe');
    expect(emitted).toEqual(['rss reader']);
  });

  it('hides the result of a preview once the query is edited, and shows it again for that query', () => {
    const fixture = previewed(FOUR_POSTS);
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
    importer.preview.mockReturnValue(
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
    expect(combined.querySelector('code')?.textContent).toBe(
      '("rss reader" || "feed reader" || rssreader) -gazette',
    );
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
