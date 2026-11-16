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
  let entries: EntriesStore;
  let followedStories: FollowedStoriesStore;

  const followUrl = 'https://api.test/api/trending/stories/12/follow';
  const followedUrl = 'https://api.test/api/trending/followed';
  const noContent = { status: 204, statusText: 'No Content' };
  const failed = { status: 500, statusText: 'err' };
  const problem = { type: 'x', title: 't', status: 500 };
  const card = (followed: boolean): EntryDto =>
    anEntry({ id: 7, story: aStory({ id: 12, followed }) });
  const action = () => TestBed.inject(TrendingFollowAction);
  const followedOnScreen = (entry: EntryDto) => followedStories.isFollowed(entry.story!);

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
    entries = TestBed.inject(EntriesStore);
    followedStories = TestBed.inject(FollowedStoriesStore);
  });

  afterEach(() => ctrl.verify());

  it('follows the story at once and reloads the followed stories once the server took it', () => {
    const entry = card(false);

    action().run(entry);

    expect(followedOnScreen(entry)).toBe(true);
    const request = ctrl.expectOne(followUrl);
    expect(request.request.method).toBe('PUT');
    ctrl.expectNone(followedUrl);
    request.flush(null, noContent);
    ctrl.expectOne(followedUrl);
    expect(followedOnScreen(entry)).toBe(true);
  });

  it('unfollows a followed story with a DELETE, without a reload', () => {
    const entry = card(true);

    action().run(entry);

    expect(followedOnScreen(entry)).toBe(false);
    const request = ctrl.expectOne(followUrl);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, noContent);
    ctrl.expectNone(followedUrl);
    expect(followedOnScreen(entry)).toBe(false);
  });

  it('shows the story followed again with the banner when an unfollow fails, and unfollows on retry', () => {
    const entry = card(true);

    action().run(entry);
    ctrl.expectOne(followUrl).flush(problem, failed);

    expect(followedOnScreen(entry)).toBe(true);
    expect(entries.error()).not.toBeNull();

    entries.retry();
    expect(followedOnScreen(entry)).toBe(false);
    const retried = ctrl.expectOne(followUrl);
    expect(retried.request.method).toBe('DELETE');
    retried.flush(null, noContent);
    expect(entries.error()).toBeNull();
  });

  it('sends nothing extra on a second click while the first is on the wire', () => {
    const entry = card(false);

    action().run(entry);
    action().run(entry);

    ctrl.expectOne(followUrl).flush(null, noContent);
    ctrl.expectOne(followedUrl);
    expect(followedOnScreen(entry)).toBe(true);
  });

  it('does nothing for a row with no story', () => {
    action().run(anEntry({ id: 7 }));

    ctrl.expectNone(followUrl);
  });
});
