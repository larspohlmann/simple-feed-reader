import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { Problem } from '../../core/problem';
import { ListPreferences } from '../list/list-preferences.service';
import { Selection } from '../query/query';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { STORY_ENTRIES } from '../state/story-entries';
import { ReaderRouteState } from './reader-route-state.service';
import { StoryOverlay } from './story-overlay.service';

describe('StoryOverlay (#2329)', () => {
  let storyId: ReturnType<typeof signal<number | null>>;
  let error: ReturnType<typeof signal<Problem | null>>;
  let order: ReturnType<typeof signal<'newest' | 'oldest'>>;
  let navigate: jest.Mock;
  let load: jest.Mock;
  let dismissError: jest.Mock;
  let reloadFollowed: jest.Mock;

  function setup(open: number | null): StoryOverlay {
    storyId = signal(open);
    error = signal<Problem | null>(null);
    order = signal<'newest' | 'oldest'>('newest');
    navigate = jest.fn(() => Promise.resolve(true));
    load = jest.fn();
    dismissError = jest.fn();
    reloadFollowed = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        StoryOverlay,
        { provide: Router, useValue: { navigate } },
        { provide: ReaderRouteState, useValue: { storyId } },
        { provide: STORY_ENTRIES, useValue: { load, error, dismissError } },
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
    const overlay = TestBed.inject(StoryOverlay);
    TestBed.tick();
    return overlay;
  }

  it("loads the open story's list, in the order the reader keeps for it", () => {
    const overlay = setup(42);

    expect(overlay.open()).toBe(true);
    expect(load).toHaveBeenLastCalledWith({ view: 'all', storyId: 42 });
    order.set('oldest');
    TestBed.tick();
    expect(load).toHaveBeenLastCalledWith({ view: 'all', storyId: 42, order: 'oldest' });
  });

  it('drops the story list once no story is open', () => {
    const overlay = setup(42);

    storyId.set(null);
    TestBed.tick();

    expect(overlay.open()).toBe(false);
    expect(load).toHaveBeenLastCalledWith(null);
  });

  it('closes onto the list underneath, whose URL it keeps', () => {
    setup(42).close();

    expect(navigate).toHaveBeenCalledWith(['/'], { queryParamsHandling: 'preserve' });
  });

  it('gives way to the list underneath when the story is gone, and refreshes the sidebar', () => {
    setup(42);

    error.set({ type: 'trending_story_not_found', title: 'Trending story not found', status: 404 });
    TestBed.tick();

    expect(dismissError).toHaveBeenCalled();
    expect(reloadFollowed).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith(['/'], {
      queryParamsHandling: 'preserve',
      replaceUrl: true,
    });
  });

  it('leaves any other failure to the banner', () => {
    setup(42);

    error.set({ type: 'about:blank', title: 'Server error', status: 500 });
    TestBed.tick();

    expect(navigate).not.toHaveBeenCalled();
  });
});
