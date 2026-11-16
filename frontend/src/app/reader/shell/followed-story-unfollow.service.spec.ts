import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { Problem } from '../../core/problem';
import { selectionQueryParams, Selection } from '../query/query';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { FollowedStoryUnfollow } from './followed-story-unfollow.service';
import { ReaderRouteState } from './reader-route-state.service';

describe('FollowedStoryUnfollow (#2128)', () => {
  const trending = { queryParams: selectionQueryParams({ view: 'trending' }) };
  let navigate: jest.Mock;
  let unfollow: jest.Mock;
  let load: jest.Mock;
  let markStoryUnfollowedLocally: jest.Mock;
  let dismissError: jest.Mock;
  let error: ReturnType<typeof signal<Problem | null>>;
  let selection: ReturnType<typeof signal<Selection>>;

  function setup(onScreen: Selection, succeeds = true): FollowedStoryUnfollow {
    navigate = jest.fn(() => Promise.resolve(true));
    unfollow = jest.fn((_id: number, onSuccess?: () => void) => {
      if (succeeds) onSuccess?.();
    });
    load = jest.fn();
    markStoryUnfollowedLocally = jest.fn();
    dismissError = jest.fn();
    error = signal<Problem | null>(null);
    selection = signal<Selection>(onScreen);
    TestBed.configureTestingModule({
      providers: [
        FollowedStoryUnfollow,
        { provide: Router, useValue: { navigate } },
        { provide: ReaderRouteState, useValue: { selection } },
        { provide: FollowedStoriesStore, useValue: { unfollow, load } },
        { provide: EntriesStore, useValue: { error, markStoryUnfollowedLocally, dismissError } },
      ],
    });
    return TestBed.inject(FollowedStoryUnfollow);
  }

  describe('the list header action', () => {
    it('is offered only on a followed story', () => {
      expect(setup({ kind: 'followed-story', id: 42 }).viewing()).toBe(true);
      selection.set({ kind: 'trending', window: 'day' });
      expect(TestBed.inject(FollowedStoryUnfollow).viewing()).toBe(false);
      selection.set({ kind: 'saved-search', id: 42, unread: false });
      expect(TestBed.inject(FollowedStoryUnfollow).viewing()).toBe(false);
    });

    it('names the story on screen as the active one', () => {
      expect(setup({ kind: 'followed-story', id: 42 }).activeId()).toBe(42);
      selection.set({ kind: 'all', unread: false });
      expect(TestBed.inject(FollowedStoryUnfollow).activeId()).toBeNull();
    });

    it('unfollows the story on screen and opens Trending in this window once it is done', () => {
      setup({ kind: 'followed-story', id: 42 }).unfollowOpen();

      expect(unfollow).toHaveBeenCalledWith(42, expect.any(Function));
      expect(navigate).toHaveBeenCalledWith(['/'], trending);
    });

    it('stays on the story when the unfollow fails', () => {
      setup({ kind: 'followed-story', id: 42 }, false).unfollowOpen();

      expect(navigate).not.toHaveBeenCalled();
      expect(markStoryUnfollowedLocally).not.toHaveBeenCalled();
    });

    it('does nothing off a followed story', () => {
      setup({ kind: 'trending', window: 'day' }).unfollowOpen();

      expect(unfollow).not.toHaveBeenCalled();
    });
  });

  describe('unfollowing from anywhere', () => {
    it('shows the story unfollowed on the cards on screen', () => {
      setup({ kind: 'trending', window: 'day' }).unfollow(7);

      expect(markStoryUnfollowedLocally).toHaveBeenCalledWith(7);
      expect(navigate).not.toHaveBeenCalled();
    });

    it('leaves another followed story open', () => {
      setup({ kind: 'followed-story', id: 42 }).unfollow(7);

      expect(navigate).not.toHaveBeenCalled();
    });

    it('opens Trending when the story unfollowed is the one on screen', () => {
      setup({ kind: 'followed-story', id: 42 }).unfollow(42);

      expect(navigate).toHaveBeenCalledWith(['/'], trending);
    });
  });

  describe('a story that is no longer followed (unfollowed elsewhere)', () => {
    const notFollowed: Problem = {
      type: 'trending_story_not_followed',
      title: 'Trending story not followed',
      status: 404,
    };

    it('replaces the dead list with Trending, drops the banner and refreshes the sidebar', () => {
      setup({ kind: 'followed-story', id: 42 });

      error.set(notFollowed);
      TestBed.tick();

      expect(dismissError).toHaveBeenCalled();
      expect(load).toHaveBeenCalled();
      expect(navigate).toHaveBeenCalledWith(['/'], { ...trending, replaceUrl: true });
    });

    it('leaves any other failure to the banner', () => {
      setup({ kind: 'followed-story', id: 42 });

      error.set({ type: 'about:blank', title: 'Server error', status: 500 });
      TestBed.tick();

      expect(navigate).not.toHaveBeenCalled();
      expect(dismissError).not.toHaveBeenCalled();
    });

    it('ignores the problem off a followed story', () => {
      setup({ kind: 'trending', window: 'day' });

      error.set(notFollowed);
      TestBed.tick();

      expect(navigate).not.toHaveBeenCalled();
    });
  });
});
