import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { aStory } from '../../../../testing/entry-testing';
import { SelectionKind } from '../../query/query';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { TrendingFollowAction } from '../trending-follow-action';
import { TrendingHideAction } from '../trending-hide-action';
import { STORY_ACTIONS, provideTrendingStoryActions } from './story-actions';

describe('provideTrendingStoryActions (#2272)', () => {
  const follow = { toggle: jest.fn() };
  const hide = { run: jest.fn() };
  const followedStories = { isFollowed: jest.fn(() => true) };
  const selection = signal<{ kind: SelectionKind }>({ kind: 'trending' });

  beforeEach(() => {
    follow.toggle.mockReset();
    hide.run.mockReset();
    selection.set({ kind: 'trending' });
    TestBed.configureTestingModule({
      providers: [
        { provide: ReaderRouteState, useValue: { selection } },
        provideTrendingStoryActions(),
        { provide: TrendingFollowAction, useValue: follow },
        { provide: TrendingHideAction, useValue: hide },
        { provide: FollowedStoriesStore, useValue: followedStories },
      ],
    });
  });

  it('provides the actions on Trending and none on another list', () => {
    const actions = TestBed.inject(STORY_ACTIONS);

    expect(actions()).not.toBeNull();
    selection.set({ kind: 'all' });
    expect(actions()).toBeNull();
  });

  it('follows and hides the story', () => {
    const story = aStory({ id: 4 });
    const actions = TestBed.inject(STORY_ACTIONS)()!;

    actions.follow(story);
    actions.hide(story);

    expect(follow.toggle).toHaveBeenCalledWith(story);
    expect(hide.run).toHaveBeenCalledWith(story);
  });

  it('asks the followed stories whether a story is followed', () => {
    const story = aStory({ id: 12, followed: false });

    expect(TestBed.inject(STORY_ACTIONS)()!.isFollowed(story)).toBe(true);
    expect(followedStories.isFollowed).toHaveBeenCalledWith(story);
  });
});
