import { computed } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { FollowedStoriesResponseDto, FollowedStoryDto } from '../trending/trending.models';
import { TrendingApi } from '../trending/trending-api';
import { aStory } from '../../../testing/entry-testing';
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
  const reply = (stories: FollowedStoryDto[]): FollowedStoriesResponseDto => ({
    stories,
    nextBuildDueAt: null,
  });

  function setup(api: Partial<TrendingApi>): FollowedStoriesStore {
    TestBed.configureTestingModule({
      providers: [FollowedStoriesStore, { provide: TrendingApi, useValue: api }],
    });
    return TestBed.inject(FollowedStoriesStore);
  }

  const answering = (stories: FollowedStoryDto[] = rows) => jest.fn(() => of(reply(stories)));

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
      const response = new Subject<FollowedStoriesResponseDto>();
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
        .mockReturnValue(of(reply(rows)));
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(2);
      expect(store.stories()).toEqual(rows);
    });
  });

  describe('the next build', () => {
    const FIVE_MINUTES_MS = 5 * 60 * 1000;
    const dueIn = (milliseconds: number) =>
      new Date(Date.now() + milliseconds).toISOString().replace('Z', '+00:00');

    it('skips a stale tick until the next build is due', () => {
      const followed = jest.fn(() =>
        of({ ...reply(rows), nextBuildDueAt: dueIn(FIVE_MINUTES_MS) }),
      );
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();
      expect(followed).toHaveBeenCalledTimes(1);

      jest.advanceTimersByTime(FIVE_MINUTES_MS - SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();
      expect(followed).toHaveBeenCalledTimes(2);
    });

    it('polls every stale tick while no build time is known', () => {
      const followed = answering();
      const store = setup({ followed });
      store.load();

      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.reloadIfStale();

      expect(followed).toHaveBeenCalledTimes(3);
    });

    it('still loads on demand before the next build is due', () => {
      const followed = jest.fn(() =>
        of({ ...reply(rows), nextBuildDueAt: dueIn(FIVE_MINUTES_MS) }),
      );
      const store = setup({ followed });
      store.load();

      store.load();

      expect(followed).toHaveBeenCalledTimes(2);
    });
  });

  it('keeps the last stories when a load fails', () => {
    const followed = jest
      .fn()
      .mockReturnValueOnce(of(reply(rows)))
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

    it('posts for a story opened before the stories have loaded, and asks again once that load answers', () => {
      const pending = new Subject<FollowedStoriesResponseDto>();
      const followed = jest
        .fn()
        .mockReturnValueOnce(pending)
        .mockReturnValue(of(reply([story(3, 0), rows[1]])));
      const opened = jest.fn(() => of(undefined));
      const store = setup({ followed, opened });
      store.load();

      store.markOpened(3);
      pending.next(reply(rows));

      expect(opened).toHaveBeenCalledWith(3);
      expect(followed).toHaveBeenCalledTimes(2);
      expect(store.byId(3)?.newCount).toBe(0);
    });
  });

  describe('a load already out when the reader acts', () => {
    function loadStillOut(afterwards: FollowedStoryDto[], api: Partial<TrendingApi>) {
      const stale = new Subject<FollowedStoriesResponseDto>();
      const followed = jest
        .fn()
        .mockReturnValueOnce(of(reply(rows)))
        .mockReturnValueOnce(stale)
        .mockReturnValue(of(reply(afterwards)));
      const store = setup({ followed, ...api });
      store.load();
      jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
      store.load();
      return { store, stale, followed };
    }

    it('does not bring back a count markOpened() zeroed', () => {
      const { store, stale } = loadStillOut([story(3, 0), rows[1]], {
        opened: () => of(undefined),
      });

      store.markOpened(3);
      stale.next(reply(rows));

      expect(store.byId(3)?.newCount).toBe(0);
    });

    it('drops an answer sent after the open but before its POST answered', () => {
      const sentMeanwhile = new Subject<FollowedStoriesResponseDto>();
      const followed = jest
        .fn()
        .mockReturnValueOnce(of(reply(rows)))
        .mockReturnValueOnce(sentMeanwhile)
        .mockReturnValue(of(reply([story(3, 0), rows[1]])));
      const posted = new Subject<void>();
      const store = setup({ followed, opened: () => posted });
      store.load();
      store.markOpened(3);
      store.load();

      posted.next();
      sentMeanwhile.next(reply(rows));

      expect(followed).toHaveBeenCalledTimes(3);
      expect(store.byId(3)?.newCount).toBe(0);
    });

    it('does not bring back a row unfollow() removed', () => {
      const { store, stale } = loadStillOut([rows[1]], { unfollow: () => of(undefined) });

      store.unfollow(3);
      stale.next(reply(rows));

      expect(store.stories().map((story) => story.id)).toEqual([5]);
    });

    it('takes the server count again once a later load has answered', () => {
      const answers = [rows, rows, [story(3, 6), rows[1]]];
      const followed = jest.fn(() => of(reply(answers.shift() ?? [])));
      const store = setup({ followed, opened: () => of(undefined) });
      store.load();
      store.markOpened(3);
      store.load();
      store.load();

      expect(store.byId(3)?.newCount).toBe(6);
    });

    it('shows a story followed again after this session unfollowed it', () => {
      const store = setup({ followed: answering(), unfollow: () => of(undefined) });
      store.load();
      store.unfollow(3);

      store.load();

      expect(store.stories().map((story) => story.id)).toEqual([3, 5]);
    });
  });

  it('keeps the same list when a load answers what it already holds', () => {
    const store = setup({
      followed: jest.fn(() => of(reply(rows.map((row) => ({ ...row }))))),
    });
    store.load();
    const held = store.stories();

    store.load();

    expect(store.stories()).toBe(held);
  });

  describe('isFollowed() and setFollowing() (#2272)', () => {
    const card = (id: number, followed = false) => aStory({ id, followed });

    it('leaves a follow flag unrecomputed when a load drops no intent', () => {
      const store = setup({
        followed: jest.fn(() => of(reply(rows.map((row) => ({ ...row }))))),
      });
      store.load();
      let computations = 0;
      const flag = computed(() => {
        computations++;
        return store.isFollowed(card(3));
      });
      flag();

      store.load();
      flag();

      expect(computations).toBe(1);
    });

    it("shows the card's own flag until the followed list has answered", () => {
      const pending = new Subject<FollowedStoriesResponseDto>();
      const store = setup({ followed: () => pending });
      store.load();

      expect([store.isFollowed(card(3, false)), store.isFollowed(card(7, true))]).toEqual([
        false,
        true,
      ]);
      pending.next(reply(rows));
      expect([store.isFollowed(card(3, false)), store.isFollowed(card(7, true))]).toEqual([
        true,
        false,
      ]);
    });

    it('shows a follow at once and keeps it until the reload lists the story', () => {
      const put = new Subject<void>();
      const reload = new Subject<FollowedStoriesResponseDto>();
      const followed = jest
        .fn()
        .mockReturnValueOnce(of(reply(rows)))
        .mockReturnValueOnce(reload)
        .mockReturnValue(of(reply(rows)));
      const store = setup({ followed, follow: jest.fn(() => put) });
      store.load();

      store.setFollowing(7, true).subscribe();
      expect(store.isFollowed(card(7))).toBe(true);
      put.next();
      put.complete();
      expect(followed).toHaveBeenCalledTimes(2);
      expect(store.isFollowed(card(7))).toBe(true);

      reload.next(reply([...rows, story(7)]));
      expect(store.isFollowed(card(7))).toBe(true);
      store.load();
      expect(store.isFollowed(card(7))).toBe(false);
    });

    it('drops an answer sent while the follow was on the wire', () => {
      const put = new Subject<void>();
      const sentMeanwhile = new Subject<FollowedStoriesResponseDto>();
      const followed = jest
        .fn()
        .mockReturnValueOnce(of(reply(rows)))
        .mockReturnValueOnce(sentMeanwhile)
        .mockReturnValue(of(reply([...rows, story(7)])));
      const store = setup({ followed, follow: () => put });
      store.load();
      store.setFollowing(7, true).subscribe();
      store.load();

      put.next();
      sentMeanwhile.next(reply(rows));

      expect(store.isFollowed(card(7))).toBe(true);
      expect(store.byId(7)).not.toBeNull();
    });

    it('puts a failed follow back', () => {
      const store = setup({
        followed: answering(),
        follow: () => throwError(() => new Error('down')),
      });
      store.load();
      const failed = jest.fn();

      store.setFollowing(7, true).subscribe({ error: failed });

      expect(failed).toHaveBeenCalledTimes(1);
      expect(store.isFollowed(card(7))).toBe(false);
    });

    it('unfollows at once and drops the row once the server took it', () => {
      const removed = new Subject<void>();
      const store = setup({ followed: answering(), unfollow: jest.fn(() => removed) });
      store.load();

      store.setFollowing(3, false).subscribe();
      expect(store.isFollowed(card(3, true))).toBe(false);
      expect(store.byId(3)).not.toBeNull();
      removed.next();

      expect(store.byId(3)).toBeNull();
      expect(store.isFollowed(card(3, true))).toBe(false);
    });

    it('ignores a second click while the first follow is on the wire', () => {
      const follow = jest.fn(() => new Subject<void>());
      const unfollow = jest.fn(() => of(undefined));
      const store = setup({ followed: answering(), follow, unfollow });
      store.load();

      store.setFollowing(7, true).subscribe();
      store.setFollowing(7, false).subscribe();
      store.unfollow(7);

      expect(follow).toHaveBeenCalledTimes(1);
      expect(unfollow).not.toHaveBeenCalled();
      expect(store.isFollowed(card(7))).toBe(true);
    });

    it('sends nothing until it is subscribed to', () => {
      const follow = jest.fn(() => of(undefined));
      const store = setup({ followed: answering(), follow });

      void store.setFollowing(7, true);

      expect(follow).not.toHaveBeenCalled();
    });
  });

  describe('unfollow()', () => {
    it('drops the row on success', () => {
      const unfollow = jest.fn(() => of(undefined));
      const store = setup({ followed: answering(), unfollow });
      store.load();

      store.unfollow(3);

      expect(unfollow).toHaveBeenCalledWith(3);
      expect(store.stories()).toEqual([rows[1]]);
    });

    it('keeps the row on failure', () => {
      const store = setup({
        followed: answering(),
        unfollow: () => throwError(() => new Error('down')),
      });
      store.load();

      store.unfollow(3);

      expect(store.stories()).toEqual(rows);
    });
  });
});
