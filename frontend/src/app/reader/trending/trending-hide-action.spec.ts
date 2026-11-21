import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { aStory, aStoryArticle } from '../../../testing/entry-testing';
import { StoryRef } from './trending.models';
import { StateSaveOutcome } from '../state/entries.store';
import { ListContext } from '../state/list-context';
import { ListItem } from '../state/list-item';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { provideRouteListForTest } from '../../../testing/list-context-testing';
import { TrendingHideAction } from './trending-hide-action';

describe('TrendingHideAction (#2017)', () => {
  const hideTrendingStory = jest.fn<void, [StoryRef, StateSaveOutcome?]>();
  const items = signal<ListItem[]>([]);
  const reloadCountsQuietly = jest.fn();

  const action = (): TrendingHideAction => {
    TestBed.configureTestingModule({
      providers: [
        TrendingHideAction,
        ...provideRouteListForTest({ hideTrendingStory, items }),
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
      ],
    });
    return TestBed.inject(TrendingHideAction);
  };

  const story = aStory({ id: 4, articles: [aStoryArticle({ id: 7 }), aStoryArticle({ id: 8 })] });

  beforeEach(() => {
    items.set([story]);
    hideTrendingStory.mockReset();
    reloadCountsQuietly.mockReset();
  });

  it('hides the story and recounts once the server took it', () => {
    action().run(story);

    expect(hideTrendingStory).toHaveBeenCalledWith(story, expect.any(Object));
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
    hideTrendingStory.mock.calls[0][1]?.onSaved?.();
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('lets the card leave as each attempt starts and keeps it when the hide fails (#2066)', () => {
    action().run({ id: story.id, followed: false });
    const outcome = hideTrendingStory.mock.calls[0][1];

    const list = TestBed.inject(ListContext);
    outcome?.onStarted?.();
    expect(list.leavingIds().has(7)).toBe(true);
    outcome?.onFailed?.();
    expect(list.leavingIds().has(7)).toBe(false);
  });

  it("hides a story whose card the list does not hold, from the story's page (#2442)", () => {
    items.set([]);
    action().run({ id: 4, followed: true });
    const outcome = hideTrendingStory.mock.calls[0][1];

    outcome?.onStarted?.();
    expect(TestBed.inject(ListContext).leavingIds().size).toBe(0);
    expect(hideTrendingStory).toHaveBeenCalledWith({ id: 4, followed: true }, expect.any(Object));
  });
});
