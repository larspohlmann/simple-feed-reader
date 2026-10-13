import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { EntryStateActions } from './entry-state-actions.service';
import { EntriesStore } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { ReaderRouteState } from './reader-route-state.service';
import { ToastService } from '../../shared/toast/toast.service';
import { Problem } from '../../core/problem';
import { EntryDto } from '../models';
import { anEntry } from '../../../testing/entry-testing';

describe('EntryStateActions liking (#1600)', () => {
  const setLiked = jest.fn<void, [EntryDto, boolean, (problem: Problem) => void]>();
  const show = jest.fn();
  let actions: EntryStateActions;

  beforeEach(() => {
    setLiked.mockReset();
    show.mockReset();
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: { setLiked } },
        { provide: SubscriptionsStore, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: { show } },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
  });

  it('likes an unliked post and unlikes a liked one', () => {
    const unliked = anEntry({ social: { network: 'bluesky', isLiked: false, canLike: true } });
    const liked = anEntry({ social: { network: 'bluesky', isLiked: true, canLike: true } });

    actions.like(unliked);
    actions.like(liked);

    expect(setLiked.mock.calls.map(([entry, isLiked]) => [entry, isLiked])).toEqual([
      [unliked, true],
      [liked, false],
    ]);
  });

  it("toasts the problem's own message when the like fails", () => {
    actions.like(anEntry({ social: { network: 'mastodon', isLiked: false, canLike: true } }));
    const onError = setLiked.mock.calls[0][2];

    onError({
      type: 'social_post_not_found',
      title: 'Post no longer available',
      status: 404,
      detail: 'The post is no longer on Mastodon.',
    });

    expect(show).toHaveBeenCalledWith({ message: 'The post is no longer on Mastodon.' });
  });
});
