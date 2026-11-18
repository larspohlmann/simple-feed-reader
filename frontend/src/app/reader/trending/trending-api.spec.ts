import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { aStory } from '../../../testing/entry-testing';
import { ListPage } from '../state/list-page';
import { TrendingApi } from './trending-api';
import { FollowedStoriesResponseDto } from './trending.models';
import { TrendingBuiltAt } from './trending-built-at';

describe('TrendingApi (#2017)', () => {
  let api: TrendingApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(TrendingApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it("reads a window's stories as a list page with no further page", () => {
    let page: ListPage | undefined;
    api.page('week').subscribe((answer) => (page = answer));
    const request = ctrl.expectOne((each) => each.url === 'https://api.test/api/trending');
    expect(request.request.params.get('window')).toBe('week');
    request.flush({ window: 'week', builtAt: null, stories: [aStory({ id: 9 })] });

    expect(page).toEqual({ items: [aStory({ id: 9 })], nextCursor: null });
  });

  it("records when the window's stories were built (#2264)", () => {
    api.page('week').subscribe();
    ctrl
      .expectOne((each) => each.url === 'https://api.test/api/trending')
      .flush({ window: 'week', builtAt: '2026-11-16T07:00:00+00:00', stories: [] });

    expect(TestBed.inject(TrendingBuiltAt).at('week')).toBe('2026-11-16T07:00:00+00:00');
  });

  const answered = (method: string, url: string, body: unknown = null) => {
    const request = ctrl.expectOne(`https://api.test${url}`);
    expect([request.request.method, request.request.body]).toEqual([method, body]);
    request.flush(null, { status: 204, statusText: 'No Content' });
  };

  it('hides a story by its id (#2128)', () => {
    api.hideStory(12).subscribe();

    answered('POST', '/api/trending/hidden', { storyId: 12 });
  });

  it('follows a story by its id (#2128)', () => {
    api.follow(12).subscribe();

    answered('PUT', '/api/trending/stories/12/follow');
  });

  it('unfollows a story by its id (#2128)', () => {
    api.unfollow(12).subscribe();

    answered('DELETE', '/api/trending/stories/12/follow');
  });

  it('records that a followed story was opened (#2128)', () => {
    api.opened(12).subscribe();

    answered('POST', '/api/trending/stories/12/opened');
  });

  it("reads the reader's followed stories (#2128)", () => {
    const followed = {
      stories: [
        {
          id: 3,
          headline: 'Storm',
          newCount: 2,
          quiet: false,
          lastJoinedAt: '2026-11-16T07:00:00+00:00',
        },
      ],
    };
    let answer: FollowedStoriesResponseDto | undefined;
    api.followed().subscribe((stories) => (answer = stories));
    const request = ctrl.expectOne('https://api.test/api/trending/followed');
    request.flush(followed);

    expect([request.request.method, answer]).toEqual(['GET', followed]);
  });
});
