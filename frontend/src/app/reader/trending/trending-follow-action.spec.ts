import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { anEntry, aStory } from '../../../testing/entry-testing';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { TrendingFollowAction } from './trending-follow-action';

describe('TrendingFollowAction (#2128)', () => {
  let ctrl: HttpTestingController;
  let store: EntriesStore;
  let followedStories: { load: jest.Mock; loadAfterFollowing: jest.Mock };

  const card = (followed: boolean): EntryDto =>
    anEntry({ id: 7, story: aStory({ id: 12, followed }) });
  const action = () => TestBed.inject(TrendingFollowAction);
  const followedOnScreen = () => store.entries()[0].story?.followed;
  const loadTrending = (row: EntryDto) => {
    store.load({ view: 'trending', window: 'day' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/trending')
      .flush({ window: 'day', builtAt: null, stories: [row] });
  };

  beforeEach(() => {
    followedStories = { load: jest.fn(), loadAfterFollowing: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        TrendingFollowAction,
        { provide: FollowedStoriesStore, useValue: followedStories },
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
    store = TestBed.inject(EntriesStore);
  });

  afterEach(() => ctrl.verify());

  it('follows the story at once and keeps it followed once the server took it', () => {
    loadTrending(card(false));

    action().run(store.entries()[0]);

    expect(followedOnScreen()).toBe(true);
    const request = ctrl.expectOne('https://api.test/api/trending/stories/12/follow');
    expect(request.request.method).toBe('PUT');
    expect(followedStories.loadAfterFollowing).not.toHaveBeenCalled();
    request.flush(null, { status: 204, statusText: 'No Content' });
    expect(followedOnScreen()).toBe(true);
    expect(followedStories.loadAfterFollowing).toHaveBeenCalledWith(12);
  });

  it('reloads the followed stories once an unfollow has landed', () => {
    loadTrending(card(true));

    action().run(store.entries()[0]);

    ctrl
      .expectOne('https://api.test/api/trending/stories/12/follow')
      .flush(null, { status: 204, statusText: 'No Content' });
    expect(followedStories.load).toHaveBeenCalledTimes(1);
    expect(followedStories.loadAfterFollowing).not.toHaveBeenCalled();
  });

  it('unfollows a followed story, and shows it followed again when the request fails', () => {
    loadTrending(card(true));

    action().run(store.entries()[0]);

    expect(followedOnScreen()).toBe(false);
    const request = ctrl.expectOne('https://api.test/api/trending/stories/12/follow');
    expect(request.request.method).toBe('DELETE');
    request.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(followedOnScreen()).toBe(true);
    expect(store.error()).not.toBeNull();
    expect(followedStories.load).not.toHaveBeenCalled();
    expect(followedStories.loadAfterFollowing).not.toHaveBeenCalled();
  });
});
