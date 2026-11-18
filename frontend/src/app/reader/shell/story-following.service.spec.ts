import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { TrendingFollowAction } from '../trending/trending-follow-action';
import { StoryFollowing } from './story-following.service';
import { StoryOverlay } from './story-overlay.service';

describe('StoryFollowing (#2128, #2329)', () => {
  let toggle: jest.Mock;

  function setup(storyId: number | null, followedOnServer = true): StoryFollowing {
    toggle = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        StoryFollowing,
        { provide: StoryOverlay, useValue: { storyId: signal(storyId) } },
        {
          provide: FollowedStoriesStore,
          useValue: {
            isFollowed: (story: { id: number; followed: boolean }) => story.followed,
          },
        },
        { provide: OpenStory, useValue: { followedOnServer: () => followedOnServer } },
        { provide: TrendingFollowAction, useValue: { toggle } },
      ],
    });
    return TestBed.inject(StoryFollowing);
  }

  afterEach(() => TestBed.resetTestingModule());

  it('asks the followed stories whether the open story is followed, from what its page said', () => {
    expect(setup(42).following()).toBe(true);
    TestBed.resetTestingModule();
    expect(setup(42, false).following()).toBe(false);
    TestBed.resetTestingModule();
    expect(setup(null).following()).toBe(false);
  });

  it('toggles following the open story', () => {
    setup(42, false).toggleOpen();

    expect(toggle).toHaveBeenCalledWith({ id: 42, followed: false });
  });

  it('does nothing with no story open', () => {
    setup(null).toggleOpen();

    expect(toggle).not.toHaveBeenCalled();
  });
});
