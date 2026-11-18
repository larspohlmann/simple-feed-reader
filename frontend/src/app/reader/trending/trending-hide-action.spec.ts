import { TestBed } from '@angular/core/testing';
import { aStory, aStoryArticle } from '../../../testing/entry-testing';
import { TrendingStoryDto } from './trending.models';
import { EntriesStore, StateSaveOutcome } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { EntryStateActions } from '../shell/entry-state-actions.service';
import { TrendingHideAction } from './trending-hide-action';

describe('TrendingHideAction (#2017)', () => {
  const hideTrendingStory = jest.fn<void, [TrendingStoryDto, StateSaveOutcome?]>();
  const reloadCountsQuietly = jest.fn();
  const markLeaving = jest.fn();
  const stayInList = jest.fn();

  const action = (): TrendingHideAction => {
    TestBed.configureTestingModule({
      providers: [
        TrendingHideAction,
        { provide: EntriesStore, useValue: { hideTrendingStory } },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: EntryStateActions, useValue: { markLeaving, stayInList } },
      ],
    });
    return TestBed.inject(TrendingHideAction);
  };

  const story = aStory({ id: 4, articles: [aStoryArticle({ id: 7 }), aStoryArticle({ id: 8 })] });

  beforeEach(() => {
    hideTrendingStory.mockReset();
    reloadCountsQuietly.mockReset();
    markLeaving.mockReset();
    stayInList.mockReset();
  });

  it('hides the story and recounts once the server took it', () => {
    action().run(story);

    expect(hideTrendingStory).toHaveBeenCalledWith(story, expect.any(Object));
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
    hideTrendingStory.mock.calls[0][1]?.onSaved?.();
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('lets the card leave as each attempt starts and keeps it when the hide fails (#2066)', () => {
    action().run(story);
    const outcome = hideTrendingStory.mock.calls[0][1];

    outcome?.onStarted?.();
    expect(markLeaving).toHaveBeenCalledWith(7);
    outcome?.onFailed?.();
    expect(stayInList).toHaveBeenCalledWith(7);
  });
});
