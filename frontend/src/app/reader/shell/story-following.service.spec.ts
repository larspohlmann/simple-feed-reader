import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { Problem } from '../../core/problem';
import { selectionQueryParams, Selection } from '../query/query';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { TrendingFollowAction } from '../trending/trending-follow-action';
import { StoryFollowing } from './story-following.service';
import { ReaderRouteState } from './reader-route-state.service';

describe('StoryFollowing (#2128, #2329)', () => {
  const trending = { queryParams: selectionQueryParams({ view: 'trending' }) };
  let navigate: jest.Mock;
  let unfollow: jest.Mock;
  let load: jest.Mock;
  let toggle: jest.Mock;
  let dismissError: jest.Mock;
  let error: ReturnType<typeof signal<Problem | null>>;
  let selection: ReturnType<typeof signal<Selection>>;

  function setup(onScreen: Selection, followedOnServer = true): StoryFollowing {
    navigate = jest.fn(() => Promise.resolve(true));
    unfollow = jest.fn();
    load = jest.fn();
    toggle = jest.fn();
    dismissError = jest.fn();
    error = signal<Problem | null>(null);
    selection = signal<Selection>(onScreen);
    TestBed.configureTestingModule({
      providers: [
        StoryFollowing,
        { provide: Router, useValue: { navigate } },
        { provide: ReaderRouteState, useValue: { selection } },
        {
          provide: FollowedStoriesStore,
          useValue: {
            unfollow,
            load,
            isFollowed: (story: { id: number; followed: boolean }) => story.followed,
          },
        },
        { provide: OpenStory, useValue: { followedOnServer: () => followedOnServer } },
        { provide: TrendingFollowAction, useValue: { toggle } },
        { provide: EntriesStore, useValue: { error, dismissError } },
      ],
    });
    return TestBed.inject(StoryFollowing);
  }

  describe('the list header action', () => {
    it("is offered on a story's list alone", () => {
      expect(setup({ kind: 'trending-story', id: 42 }).viewing()).toBe(true);
      selection.set({ kind: 'trending', window: 'day' });
      expect(TestBed.inject(StoryFollowing).viewing()).toBe(false);
      selection.set({ kind: 'saved-search', id: 42, unread: false });
      expect(TestBed.inject(StoryFollowing).viewing()).toBe(false);
    });

    it('names the story on screen as the active one', () => {
      expect(setup({ kind: 'trending-story', id: 42 }).activeId()).toBe(42);
      selection.set({ kind: 'all', unread: false });
      expect(TestBed.inject(StoryFollowing).activeId()).toBeNull();
    });

    it('asks the followed stories whether the story on screen is followed, from what its page said', () => {
      expect(setup({ kind: 'trending-story', id: 42 }).following()).toBe(true);
      TestBed.resetTestingModule();
      expect(setup({ kind: 'trending-story', id: 42 }, false).following()).toBe(false);
      TestBed.resetTestingModule();
      expect(setup({ kind: 'trending', window: 'day' }).following()).toBe(false);
    });

    it('toggles following the story on screen and stays on it', () => {
      setup({ kind: 'trending-story', id: 42 }, false).toggleOpen();

      expect(toggle).toHaveBeenCalledWith({ id: 42, followed: false });
      expect(navigate).not.toHaveBeenCalled();
    });

    it("does nothing off a story's list", () => {
      setup({ kind: 'trending', window: 'day' }).toggleOpen();

      expect(toggle).not.toHaveBeenCalled();
    });
  });

  describe('unfollowing from the sidebar', () => {
    it('unfollows and stays where the reader is, the story on screen included', () => {
      setup({ kind: 'trending-story', id: 42 }).unfollow(42);

      expect(unfollow).toHaveBeenCalledWith(42);
      expect(navigate).not.toHaveBeenCalled();
    });
  });

  describe('a story that is gone', () => {
    const notFound: Problem = {
      type: 'trending_story_not_found',
      title: 'Trending story not found',
      status: 404,
    };

    it('replaces the dead list with Trending, drops the banner and refreshes the sidebar', () => {
      setup({ kind: 'trending-story', id: 42 });

      error.set(notFound);
      TestBed.tick();

      expect(dismissError).toHaveBeenCalled();
      expect(load).toHaveBeenCalled();
      expect(navigate).toHaveBeenCalledWith(['/'], { ...trending, replaceUrl: true });
    });

    it('leaves any other failure to the banner', () => {
      setup({ kind: 'trending-story', id: 42 });

      error.set({ type: 'about:blank', title: 'Server error', status: 500 });
      TestBed.tick();

      expect(navigate).not.toHaveBeenCalled();
      expect(dismissError).not.toHaveBeenCalled();
    });

    it("ignores the problem off a story's list", () => {
      setup({ kind: 'trending', window: 'day' });

      error.set(notFound);
      TestBed.tick();

      expect(navigate).not.toHaveBeenCalled();
    });
  });
});
