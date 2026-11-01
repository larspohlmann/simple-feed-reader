import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Router, provideRouter } from '@angular/router';
import { EMPTY, Observable, of } from 'rxjs';
import { EntryStateActions } from './entry-state-actions.service';
import { EntriesStore, StateSaveOutcome } from '../state/entries.store';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { ReaderRouteState } from './reader-route-state.service';
import { ToastService } from '../../shared/toast/toast.service';
import { Problem } from '../../core/problem';
import { EntryDto, EntryStatePatch } from '../models';
import { anEntry } from '../../../testing/entry-testing';
import { PostActions } from '../article/composer/post-actions.service';

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
        { provide: PostActions, useValue: {} },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
  });

  it('likes an unliked post and unlikes a liked one', () => {
    const unliked = anEntry({
      social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
    });
    const liked = anEntry({
      social: { network: 'bluesky', isLiked: true, canLike: true, isOwn: false },
    });

    actions.like(unliked);
    actions.like(liked);

    expect(setLiked.mock.calls.map(([entry, isLiked]) => [entry, isLiked])).toEqual([
      [unliked, true],
      [liked, false],
    ]);
  });

  it("toasts the problem's own message when the like fails", () => {
    actions.like(
      anEntry({ social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: false } }),
    );
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
        { provide: PostActions, useValue: {} },
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
  const reloadCountsQuietly = jest.fn();
  const setState = jest.fn<void, [number, EntryStatePatch, StateSaveOutcome?]>();
  const openEntryId = signal<number | null>(null);
  const openEntry = signal<EntryDto | null>(null);
  let actions: EntryStateActions;

  beforeEach(() => {
    markArticleRead.mockReset();
    markArticleUnread.mockReset();
    reloadCountsQuietly.mockReset();
    setState.mockReset();
    openEntryId.set(null);
    openEntry.set(null);
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: { setState } },
        {
          provide: SubscriptionsStore,
          useValue: {
            bumpViewed: jest.fn(),
            markArticleRead,
            markArticleUnread,
            reloadCountsQuietly,
          },
        },
        {
          provide: SavedSearchesStore,
          useValue: { markEntryRead: jest.fn(), markEntryUnread: jest.fn() },
        },
        {
          provide: ReaderRouteState,
          useValue: {
            openEntryId,
            openEntry,
            selection: () => ({ kind: 'all' }),
          },
        },
        { provide: ToastService, useValue: { show: jest.fn() } },
        { provide: PostActions, useValue: {} },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
  });

  const outcome = (call = 0): StateSaveOutcome => setState.mock.calls[call][2] ?? {};

  it('ticking an unread entry reads the article, and a failed save puts it back', () => {
    const entry = anEntry();

    actions.toggleRead(entry);

    expect(markArticleRead).toHaveBeenCalledWith(entry);
    expect(markArticleUnread).not.toHaveBeenCalled();
    outcome().onFailed?.();
    expect(markArticleUnread).toHaveBeenCalledWith(entry);
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });

  it('opening an unread entry reads the article, and a failed save puts it back', () => {
    const entry = anEntry();

    openEntry.set(entry);
    openEntryId.set(entry.id);
    TestBed.tick();

    expect(markArticleRead).toHaveBeenCalledWith(entry);
    expect(markArticleUnread).not.toHaveBeenCalled();
    outcome().onFailed?.();
    expect(markArticleUnread).toHaveBeenCalledWith(entry);
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });

  it('recounts the scope totals once a read is saved, by ticking or by opening', () => {
    actions.toggleRead(anEntry({ id: 1 }));
    outcome().onSaved?.();
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);

    const opened = anEntry({ id: 2 });
    openEntry.set(opened);
    openEntryId.set(opened.id);
    TestBed.tick();
    outcome(1).onSaved?.();

    expect(reloadCountsQuietly).toHaveBeenCalledTimes(2);
  });

  it('reads and recounts when following the original link reads the entry, but not for a hidden one', () => {
    actions.openOriginal(anEntry({ id: 1 }));
    outcome().onSaved?.();
    actions.openOriginal(anEntry({ id: 2, isHidden: true }));
    outcome(1).onSaved?.();

    expect(markArticleRead).toHaveBeenCalledTimes(1);
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
  });

  it('does not recount when un-ticking, which reads nothing', () => {
    actions.toggleRead(anEntry({ isViewed: true }));
    outcome().onSaved?.();

    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });
});

describe('EntryStateActions deleting my own post (#1972)', () => {
  const deletePost = jest.fn<Observable<void>, [EntryDto]>();
  const reloadCountsQuietly = jest.fn();
  const openEntryId = signal<number | null>(null);
  let actions: EntryStateActions;
  let navigate: jest.SpyInstance;

  beforeEach(() => {
    deletePost.mockReset().mockReturnValue(of(undefined));
    reloadCountsQuietly.mockReset();
    openEntryId.set(null);
    TestBed.configureTestingModule({
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: {} },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId, openEntry: signal(null), selection: () => ({ kind: 'all' }) },
        },
        { provide: ToastService, useValue: {} },
        { provide: PostActions, useValue: { deletePost } },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  it('lets the row leave and recounts once the post is gone', () => {
    actions.deletePost(anEntry({ id: 7 }));

    expect(actions.leavingIds().has(7)).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('closes the article of the deleted post as Back does', () => {
    openEntryId.set(7);

    actions.deletePost(anEntry({ id: 7 }));

    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { entry: null, link: null } }),
    );
  });

  it('keeps the row when the post stays', () => {
    deletePost.mockReturnValue(EMPTY);

    actions.deletePost(anEntry({ id: 7 }));

    expect(actions.leavingIds().size).toBe(0);
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });
});
