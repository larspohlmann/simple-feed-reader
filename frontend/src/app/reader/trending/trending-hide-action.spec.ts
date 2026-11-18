import { TestBed } from '@angular/core/testing';
import { aStory, aStoryArticle } from '../../../testing/entry-testing';
import { TrendingStoryDto } from './trending.models';
import { StateSaveOutcome } from '../state/entries.store';
import { ListContext } from '../state/list-context';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { provideRouteListForTest } from '../../../testing/list-context-testing';
import { TrendingHideAction } from './trending-hide-action';

describe('TrendingHideAction (#2017)', () => {
  const hideTrendingStory = jest.fn<void, [TrendingStoryDto, StateSaveOutcome?]>();
  const reloadCountsQuietly = jest.fn();

  const action = (): TrendingHideAction => {
    TestBed.configureTestingModule({
      providers: [
        TrendingHideAction,
        ...provideRouteListForTest({ hideTrendingStory }),
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
      ],
    });
    return TestBed.inject(TrendingHideAction);
  };

  const story = aStory({ id: 4, articles: [aStoryArticle({ id: 7 }), aStoryArticle({ id: 8 })] });

  beforeEach(() => {
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
    action().run({ ...story, leadEntryId: 99 });
    const outcome = hideTrendingStory.mock.calls[0][1];

    const list = TestBed.inject(ListContext);
    outcome?.onStarted?.();
    expect(list.leavingIds().has(7)).toBe(true);
    outcome?.onFailed?.();
    expect(list.leavingIds().has(7)).toBe(false);
  });
});
