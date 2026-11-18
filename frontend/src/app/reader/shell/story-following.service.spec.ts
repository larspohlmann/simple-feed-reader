import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { TrendingFollowAction } from '../trending/trending-follow-action';
import { StoryFollowing } from './story-following.service';
import { StoryListContext } from './story-layer/story-list-context';

describe('StoryFollowing (#2128, #2329)', () => {
  let toggle: jest.Mock;

  function setup(followedOnServer = true): StoryFollowing {
    toggle = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        StoryFollowing,
        {
          provide: StoryListContext,
          useValue: { selection: signal({ kind: 'trending-story', id: 42, unread: false }) },
        },
        {
          provide: FollowedStoriesStore,
          useValue: {
            isFollowed: (story: { id: number; followed: boolean }) => story.followed,
          },
        },
        {
          provide: OpenStory,
          useValue: { followedOnServer: (id: number) => id === 42 && followedOnServer },
        },
        { provide: TrendingFollowAction, useValue: { toggle } },
      ],
    });
    return TestBed.inject(StoryFollowing);
  }

  afterEach(() => TestBed.resetTestingModule());

  it("asks the followed stories whether the layer's story is followed, from what its page said", () => {
    expect(setup().following()).toBe(true);
    TestBed.resetTestingModule();
    expect(setup(false).following()).toBe(false);
  });

  it("toggles following the layer's story", () => {
    setup(false).toggleOpen();

    expect(toggle).toHaveBeenCalledWith({ id: 42, followed: false });
  });
});
