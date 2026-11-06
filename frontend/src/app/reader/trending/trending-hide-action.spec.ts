import { TestBed } from '@angular/core/testing';
import { anEntry } from '../../../testing/entry-testing';
import { EntriesStore, StateSaveOutcome } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { EntryStateActions } from '../shell/entry-state-actions.service';
import { TrendingHideAction } from './trending-hide-action';

describe('TrendingHideAction (#2017)', () => {
  const hideTrendingStory = jest.fn<void, [number, StateSaveOutcome?]>();
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

  beforeEach(() => {
    hideTrendingStory.mockReset();
    reloadCountsQuietly.mockReset();
    markLeaving.mockReset();
    stayInList.mockReset();
  });

  it('hides the story and recounts once the server took it', () => {
    action().run(anEntry({ id: 7 }));

    expect(hideTrendingStory).toHaveBeenCalledWith(7, expect.any(Object));
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
    hideTrendingStory.mock.calls[0][1]?.onSaved?.();
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('lets the card leave as each attempt starts and keeps it when the hide fails (#2066)', () => {
    action().run(anEntry({ id: 7 }));
    const outcome = hideTrendingStory.mock.calls[0][1];

    outcome?.onStarted?.();
    expect(markLeaving).toHaveBeenCalledWith(7);
    outcome?.onFailed?.();
    expect(stayInList).toHaveBeenCalledWith(7);
  });

  it('names itself through the translated Hide story label and hint', () => {
    const hide = action();

    expect([hide.icon, hide.label, hide.hint]).toEqual([
      'visibility_off',
      'reader.hideStory',
      'reader.actionHint.hideStory',
    ]);
  });
});
