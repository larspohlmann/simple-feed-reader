import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Router, provideRouter } from '@angular/router';
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

describe('EntryStateActions opening', () => {
  it('drops a standalone link card when an entry is opened from the list', () => {
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: {} },
        { provide: SubscriptionsStore, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: {} },
      ],
    });
    const navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);

    TestBed.inject(EntryStateActions).open(anEntry({ id: 514, title: 'Some Title' }));

    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { entry: '514-some-title', link: null } }),
    );
  });
});

describe('EntryStateActions reading counts (#1679)', () => {
  const markArticleRead = jest.fn();
  const markArticleUnread = jest.fn();
  const setState = jest.fn();
  let actions: EntryStateActions;

  beforeEach(() => {
    markArticleRead.mockReset();
    markArticleUnread.mockReset();
    setState.mockReset();
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: { setState } },
        {
          provide: SubscriptionsStore,
          useValue: { bumpViewed: jest.fn(), markArticleRead, markArticleUnread },
        },
        {
          provide: SavedSearchesStore,
          useValue: { markEntryRead: jest.fn(), markEntryUnread: jest.fn() },
        },
        {
          provide: ReaderRouteState,
          useValue: {
            openEntryId: signal(null),
            openEntry: signal(null),
            selection: () => ({ kind: 'all' }),
          },
        },
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
  });

  it('ticking an unread entry reads the article, and a failed save puts it back', () => {
    const entry = anEntry();

    actions.toggleRead(entry);

    expect(markArticleRead).toHaveBeenCalledWith(entry);
    setState.mock.calls[0][2]();
    expect(markArticleUnread).toHaveBeenCalledWith(entry);
  });
});
