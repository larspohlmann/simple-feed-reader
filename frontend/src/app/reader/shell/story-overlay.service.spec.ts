import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { ListPreferences } from '../list/list-preferences.service';
import { Selection } from '../query/query';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { ReaderRouteState } from './reader-route-state.service';
import { StoryOverlay } from './story-overlay.service';

describe('StoryOverlay (#2329)', () => {
  let storyId: ReturnType<typeof signal<number | null>>;
  let order: ReturnType<typeof signal<'newest' | 'oldest'>>;
  let navigate: jest.Mock;
  let reloadFollowed: jest.Mock;

  function setup(open: number | null): StoryOverlay {
    storyId = signal(open);
    order = signal<'newest' | 'oldest'>('newest');
    navigate = jest.fn(() => Promise.resolve(true));
    reloadFollowed = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        StoryOverlay,
        { provide: Router, useValue: { navigate } },
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
});
