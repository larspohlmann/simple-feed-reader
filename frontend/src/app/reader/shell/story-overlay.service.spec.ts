import { signal } from '@angular/core';
import { Location } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { Event, NavigationEnd, Router } from '@angular/router';
import { Subject } from 'rxjs';
import { ListPreferences } from '../list/list-preferences.service';
import { Selection } from '../query/query';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { storyRootState } from '../trending/story';
import { ReaderRouteState } from './reader-route-state.service';
import { StoryOverlay } from './story-overlay.service';

describe('StoryOverlay (#2329)', () => {
  let storyId: ReturnType<typeof signal<number | null>>;
  let order: ReturnType<typeof signal<'newest' | 'oldest'>>;
  let navigate: jest.Mock;
  let reloadFollowed: jest.Mock;
  let routerEvents: Subject<Event>;
  let historyState: unknown;

  function setup(open: number | null, state: unknown = null): StoryOverlay {
    storyId = signal(open);
    order = signal<'newest' | 'oldest'>('newest');
    navigate = jest.fn(() => Promise.resolve(true));
    reloadFollowed = jest.fn();
    routerEvents = new Subject<Event>();
    historyState = state;
    TestBed.configureTestingModule({
      providers: [
        StoryOverlay,
        { provide: Router, useValue: { navigate, events: routerEvents } },
        { provide: Location, useValue: { getState: () => historyState } },
        { provide: ReaderRouteState, useValue: { storyId } },
        { provide: FollowedStoriesStore, useValue: { load: reloadFollowed } },
        {
          provide: ListPreferences,
          useValue: {
            appliedTo: (selection: Selection) =>
              order() === 'oldest' ? { ...selection, order: 'oldest' } : selection,
          },
        },
      ],
    });
    return TestBed.inject(StoryOverlay);
  }

  it('names the open story, in the order the reader keeps for it', () => {
    const overlay = setup(42);

    expect(overlay.open()).toBe(true);
    expect(overlay.selection()).toEqual({ kind: 'trending-story', id: 42, unread: false });
    order.set('oldest');
    expect(overlay.selection()).toEqual({
      kind: 'trending-story',
      id: 42,
      unread: false,
      order: 'oldest',
    });
  });

  it('names no story once none is open', () => {
    const overlay = setup(42);

    storyId.set(null);

    expect(overlay.open()).toBe(false);
    expect(overlay.selection()).toBeNull();
  });

  it('closes onto the list underneath, whose URL it keeps', () => {
    setup(42).close();

    expect(navigate).toHaveBeenCalledWith(['/'], { queryParamsHandling: 'preserve' });
  });

  it('gives way to the list underneath for a story that is gone, and refreshes the sidebar', () => {
    setup(42).leaveStoryThatIsGone();

    expect(reloadFollowed).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith(['/'], {
      queryParamsHandling: 'preserve',
      replaceUrl: true,
    });
  });

  describe('a story opened from the sidebar (#2518)', () => {
    function navigateTo(open: number | null, state: unknown = null): void {
      storyId.set(open);
      historyState = state;
      routerEvents.next(new NavigationEnd(1, '/', '/'));
    }

    it('is the root, with no way back to the list beneath', () => {
      const overlay = setup(42, storyRootState(42));

      expect(overlay.open()).toBe(true);
      expect(overlay.returnsToList()).toBe(false);
    });

    it('becomes the root once the sidebar opens it over a list', () => {
      const overlay = setup(null);

      navigateTo(42, storyRootState(42));

      expect(overlay.returnsToList()).toBe(false);
    });

    it('stays the root while one of its articles opens and closes', () => {
      const overlay = setup(42, storyRootState(42));

      navigateTo(42);
      navigateTo(42);

      expect(overlay.returnsToList()).toBe(false);
    });

    it('is forgotten once the story closes, so the same story opened from a list returns to it', () => {
      const overlay = setup(42, storyRootState(42));

      navigateTo(null);
      navigateTo(42);

      expect(overlay.returnsToList()).toBe(true);
    });

    it('is forgotten once another story opens from a list', () => {
      const overlay = setup(42, storyRootState(42));

      navigateTo(7);

      expect(overlay.returnsToList()).toBe(true);
    });
  });

  it('returns to the list it lies over when opened from a story title', () => {
    expect(setup(42).returnsToList()).toBe(true);
  });

  it('returns to no list while no story is open', () => {
    expect(setup(null, storyRootState(42)).returnsToList()).toBe(false);
  });
});
