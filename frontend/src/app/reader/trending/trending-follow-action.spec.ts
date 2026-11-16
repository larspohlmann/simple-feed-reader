import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { anEntry, aStory } from '../../../testing/entry-testing';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';
import { TrendingFollowAction } from './trending-follow-action';

describe('TrendingFollowAction (#2128)', () => {
  let ctrl: HttpTestingController;
  let store: EntriesStore;

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
    TestBed.configureTestingModule({
      providers: [
        TrendingFollowAction,
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
    request.flush(null, { status: 204, statusText: 'No Content' });
    expect(followedOnScreen()).toBe(true);
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
  });
});
