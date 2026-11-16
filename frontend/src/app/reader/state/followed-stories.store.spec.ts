import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { FollowedStoryDto } from '../trending/trending.models';
import { TrendingApi } from '../trending/trending-api';
import { FollowedStoriesStore } from './followed-stories.store';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';

describe('FollowedStoriesStore (#2128)', () => {
  const story = (id: number, newCount = 0): FollowedStoryDto => ({
    id,
    headline: `Story ${id}`,
    newCount,
    quiet: false,
    lastJoinedAt: '2026-11-16T10:00:00+00:00',
  });
  const rows = [story(3, 2), story(5, 0)];

  function setup(api: Partial<TrendingApi>): FollowedStoriesStore {
    TestBed.configureTestingModule({
      providers: [FollowedStoriesStore, { provide: TrendingApi, useValue: api }],
    });
    return TestBed.inject(FollowedStoriesStore);
  }

  const answering = (stories: FollowedStoryDto[] = rows) => jest.fn(() => of({ stories }));

  beforeEach(() => jest.useFakeTimers({ now: new Date('2026-11-16T12:00:00Z') }));
  afterEach(() => jest.useRealTimers());

  it('load() adopts the response in server order', () => {
    const store = setup({ followed: answering() });

    store.load();

    expect(store.stories()).toEqual(rows);
  });

  it('byId() finds a story, and answers null for one that is not followed', () => {
    const store = setup({ followed: answering() });
    store.load();

    expect(store.byId(5)).toEqual(rows[1]);
    expect(store.byId(99)).toBeNull();
  });

  describe('reloadIfStale()', () => {
    it('loads when it never has', () => {
      const followed = answering();
      setup({ followed });
      TestBed.inject(FollowedStoriesStore).reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(1);
    });

    it('does nothing inside the freshness window', () => {
      const followed = answering();
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS - 1);
      store.reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(1);
    });

    it('loads again once the window has passed', () => {
      const followed = answering();
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(2);
    });

    it('does not stack a request on one that is still out', () => {
      const response = new Subject<{ stories: FollowedStoryDto[] }>();
      const followed = jest.fn(() => response);
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 2);
      store.reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(1);
    });

    it('asks again after a request failed', () => {
      const followed = jest
        .fn()
        .mockReturnValueOnce(throwError(() => new Error('down')))
        .mockReturnValue(of({ stories: rows }));
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(2);
      expect(store.stories()).toEqual(rows);
    });
  });

  it('keeps the last stories when a load fails', () => {
    const followed = jest
      .fn()
      .mockReturnValueOnce(of({ stories: rows }))
      .mockReturnValue(throwError(() => new Error('down')));
    const store = setup({ followed });
    store.load();

    store.load();

    expect(store.stories()).toEqual(rows);
  });

  describe('markOpened()', () => {
    it('zeroes the count before the POST answers', () => {
      const response = new Subject<void>();
      const opened = jest.fn(() => response);
      const store = setup({ followed: answering([story(3, 2), story(5, 4)]), opened });
      store.load();

      store.markOpened(3);

      expect(opened).toHaveBeenCalledWith(3);
      expect(store.byId(3)?.newCount).toBe(0);
      expect(store.byId(5)?.newCount).toBe(4);
    });

    it('leaves the other stories untouched', () => {
      const store = setup({
        followed: answering([story(3, 2), story(5, 4)]),
        opened: () => of(undefined),
      });
      store.load();

      store.markOpened(3);

      expect(store.byId(5)?.newCount).toBe(4);
    });

    it('keeps the zero when the POST fails', () => {
      const store = setup({
        followed: answering(),
        opened: () => throwError(() => new Error('down')),
      });
      store.load();

      store.markOpened(3);

      expect(store.byId(3)?.newCount).toBe(0);
    });

    it('does not post for a story that is not followed', () => {
      const opened = jest.fn(() => of(undefined));
      const store = setup({ followed: answering(), opened });
      store.load();

      store.markOpened(99);

      expect(opened).not.toHaveBeenCalled();
    });
  });

  describe('a load already out when the reader acts', () => {
    function loadStillOut(stories: FollowedStoryDto[], api: Partial<TrendingApi>) {
      const stale = new Subject<{ stories: FollowedStoryDto[] }>();
      const followed = jest.fn().mockReturnValueOnce(of({ stories })).mockReturnValueOnce(stale);
      const store = setup({ followed, ...api });
      store.load();
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.load();
      return { store, stale };
    }

    it('does not bring back a count markOpened() zeroed', () => {
      const { store, stale } = loadStillOut(rows, { opened: () => of(undefined) });

      store.markOpened(3);
      stale.next({ stories: rows });

      expect(store.byId(3)?.newCount).toBe(0);
    });

    it('does not bring back a row unfollow() removed', () => {
      const { store, stale } = loadStillOut(rows, { unfollow: () => of(undefined) });

      store.unfollow(3);
      stale.next({ stories: rows });

      expect(store.stories().map((story) => story.id)).toEqual([5]);
    });

    it('takes the server count again once a later load has answered', () => {
      const answers = [rows, rows, [story(3, 6), rows[1]]];
      const followed = jest.fn(() => of({ stories: answers.shift() ?? [] }));
      const store = setup({ followed, opened: () => of(undefined) });
      store.load();
      store.markOpened(3);
      store.load();
      store.load();

      expect(store.byId(3)?.newCount).toBe(6);
    });
  });

  describe('unfollow()', () => {
    it('drops the row on success and calls onSuccess', () => {
      const unfollow = jest.fn(() => of(undefined));
      const store = setup({ followed: answering(), unfollow });
      store.load();
      const onSuccess = jest.fn();

      store.unfollow(3, onSuccess);

      expect(unfollow).toHaveBeenCalledWith(3);
      expect(store.stories()).toEqual([rows[1]]);
      expect(onSuccess).toHaveBeenCalledTimes(1);
    });

    it('keeps the row on failure', () => {
      const store = setup({
        followed: answering(),
        unfollow: () => throwError(() => new Error('down')),
      });
      store.load();
      const onSuccess = jest.fn();

      store.unfollow(3, onSuccess);

      expect(store.stories()).toEqual(rows);
      expect(onSuccess).not.toHaveBeenCalled();
    });
  });
});
