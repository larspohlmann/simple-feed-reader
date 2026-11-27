import { TestBed } from '@angular/core/testing';
import { DestroyRef, Signal, inject, signal } from '@angular/core';
import { Router, provideRouter } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/auth/token.store';
import { EMPTY, Observable, Subject, of, throwError } from 'rxjs';
import { EntryStateActions } from './entry-state-actions.service';
import { EntriesStore, StateSaveOutcome } from '../state/entries.store';
import { ListContext } from '../state/list-context';
import { EntryRowCache, ActOutcome } from '../state/entry-row-cache';
import { ReaderApi } from '../reader-api';
import { SocialPostApi } from '../social-post-api';
import { Selection } from '../query/query';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { LabelsStore } from '../labels/labels.store';
import { LabelPill } from '../labels/labels.models';
import { ReaderRouteState } from './reader-route-state.service';
import { ToastService } from '../../shared/toast/toast.service';
import { EntryDto, EntryStatePatch } from '../models';
import { anEntry } from '../../../testing/entry-testing';
import { VoteValue } from '../vote-pill/vote';
import { ArticlePostActions } from '../article/post-actions/article-post-actions.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { RemovedPosts } from '../removed-posts.service';
import { TestListContext, provideRouteListForTest } from '../../../testing/list-context-testing';
import { ListCover } from './list-cover';
import type { ListHeading } from './list-heading.service';
import { Mark } from '../state/marks';
import { Problem } from '../../core/problem';

const aProblem: Problem = { title: 'Nope', status: 500, type: 'about:blank' };

interface Collaborators {
  entries: object;
  subscriptions: object;
  routeState: object;
  savedSearches?: object;
  toast?: object;
  rows?: object;
  labels?: object;
}

/** The list on screen lists what the route names; a spec's route state gives its selection. */
function selectionOf(routeState: object): Signal<Selection> {
  const selection = (routeState as { selection?: () => Selection }).selection;
  return (selection ?? (() => ({ kind: 'all', unread: false }))) as Signal<Selection>;
}

function createActions(collaborators: Collaborators): EntryStateActions {
  TestBed.configureTestingModule({
    imports: [provideTranslocoTesting()],
    providers: [
      EntryStateActions,
      { provide: EntryRowCache, useValue: collaborators.rows ?? {} },
      { provide: LabelsStore, useValue: collaborators.labels ?? { reload: jest.fn() } },
      provideRouter([]),
      ...provideRouteListForTest(collaborators.entries, selectionOf(collaborators.routeState)),
      { provide: SubscriptionsStore, useValue: collaborators.subscriptions },
      { provide: SavedSearchesStore, useValue: collaborators.savedSearches ?? {} },
      { provide: ReaderRouteState, useValue: collaborators.routeState },
      { provide: ToastService, useValue: collaborators.toast ?? { show: jest.fn() } },
      { provide: ArticlePostActions, useValue: {} },
    ],
  });
  return TestBed.inject(EntryStateActions);
}

describe('EntryStateActions liking (#1600)', () => {
  const setLiked = jest.fn<Observable<void>, [EntryDto, boolean, ActOutcome]>(() => EMPTY);
  const bumpCount = jest.fn();
  const showProblem = jest.fn();
  const selection = signal<Selection>({ kind: 'all', unread: false });
  let actions: EntryStateActions;

  const outcome = (call = 0): ActOutcome => setLiked.mock.calls[call][2];
  const isLeaving = (id: number) => TestBed.inject(ListContext).leavingIds().has(id);
  const problem = {
    type: 'social_post_not_found',
    title: 'Post no longer available',
    status: 404,
    detail: 'The post is no longer on Mastodon.',
  };

  beforeEach(() => {
    setLiked.mockClear();
    bumpCount.mockReset();
    showProblem.mockReset();
    selection.set({ kind: 'all', unread: false });
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        provideRouter([]),
        ...provideRouteListForTest({}, selection),
        { provide: EntryRowCache, useValue: { setLiked } },
        { provide: SubscriptionsStore, useValue: { bumpCount } },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: LabelsStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: { showProblem } },
        { provide: ArticlePostActions, useValue: {} },
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

  it('toasts the problem when the like fails', () => {
    actions.like(
      anEntry({
        social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: false },
      }),
    );

    outcome().onFailed(problem);

    expect(showProblem).toHaveBeenCalledWith(problem);
  });

  it('counts a like up and an unlike down once each goes out', () => {
    actions.like(anEntry({ id: 1, isLiked: false }));
    actions.like(anEntry({ id: 2, isLiked: true }));
    expect(bumpCount).not.toHaveBeenCalled();

    outcome(0).onStarted?.();
    outcome(1).onStarted?.();

    expect(bumpCount.mock.calls).toEqual([
      ['liked', 1],
      ['liked', -1],
    ]);
  });

  it('counts a network like of a locally liked entry nothing, and its unlike out once (#2587)', () => {
    const network = { network: 'bluesky' as const, canLike: true, isOwn: false };
    actions.like(anEntry({ id: 1, isLiked: true, social: { ...network, isLiked: false } }));
    actions.like(anEntry({ id: 2, isLiked: true, social: { ...network, isLiked: true } }));

    outcome(0).onStarted?.();
    outcome(1).onStarted?.();

    expect(bumpCount.mock.calls).toEqual([['liked', -1]]);
  });

  it('takes the count back when the like fails', () => {
    actions.like(anEntry({ id: 1, isLiked: false }));

    outcome().onStarted?.();
    outcome().onFailed(problem);

    expect(bumpCount.mock.calls).toEqual([
      ['liked', 1],
      ['liked', -1],
    ]);
  });

  it('leaves the Liked list when unliked there and comes back when that fails', () => {
    selection.set({ kind: 'liked' });
    actions.like(anEntry({ id: 9, isLiked: true }));

    outcome().onStarted?.();
    expect(isLeaving(9)).toBe(true);

    outcome().onFailed(problem);
    expect(isLeaving(9)).toBe(false);
  });

  it('stays in any other list when unliked', () => {
    actions.like(anEntry({ id: 9, isLiked: true }));

    outcome().onStarted?.();

    expect(isLeaving(9)).toBe(false);
  });
});

describe('EntryStateActions opening', () => {
  it('drops a standalone link card when an entry is opened from the list', () => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        { provide: EntryRowCache, useValue: {} },
        provideRouter([]),
        ...provideRouteListForTest({}),
        { provide: SubscriptionsStore, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: LabelsStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: {} },
        { provide: ArticlePostActions, useValue: {} },
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
    actions = createActions({
      entries: { setState },
      subscriptions: {
        bumpCount: jest.fn(),
        markArticleRead,
        markArticleUnread,
        reloadCountsQuietly,
      },
      savedSearches: { markEntryRead: jest.fn(), markEntryUnread: jest.fn() },
      routeState: { openEntryId, openEntry, selection: () => ({ kind: 'all' }) },
    });
  });

  const outcome = (call = 0): StateSaveOutcome => setState.mock.calls[call][2] ?? {};

  it('ticking an unread entry reads the article, and a failed save puts it back', () => {
    const entry = anEntry();

    actions.toggleRead(entry);

    expect(markArticleRead).toHaveBeenCalledWith(entry);
    expect(markArticleUnread).not.toHaveBeenCalled();
    outcome().onFailed?.(aProblem);
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
    outcome().onFailed?.(aProblem);
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

describe('EntryStateActions sharing (#2007)', () => {
  const bumpCount = jest.fn();
  const show = jest.fn();
  const setStateQuietly = jest.fn<void, [number, EntryStatePatch, StateSaveOutcome?]>();
  const setState = jest.fn();
  let actions: EntryStateActions;

  beforeEach(() => {
    bumpCount.mockReset();
    show.mockReset();
    setStateQuietly.mockReset();
    setState.mockReset();
    actions = createActions({
      entries: { setState, setStateQuietly },
      subscriptions: { bumpCount },
      routeState: {
        openEntryId: signal(null),
        openEntry: signal(null),
        selection: () => ({ kind: 'all' }),
      },
      toast: { show },
    });
  });

  it('shares an unshared entry: counts it and patches isShared', () => {
    actions.share(anEntry({ id: 7, isShared: false }));
    setStateQuietly.mock.calls[0][2]?.onStarted?.();

    expect(bumpCount).toHaveBeenCalledWith('shared', 1);
    expect(setStateQuietly).toHaveBeenCalledWith(7, { isShared: true }, expect.anything());
  });

  it('re-sharing a shared entry refreshes it without counting it twice', () => {
    actions.share(anEntry({ id: 7, isShared: true }));
    setStateQuietly.mock.calls[0][2]?.onStarted?.();

    expect(bumpCount).not.toHaveBeenCalled();
    expect(setStateQuietly).toHaveBeenCalledWith(7, { isShared: true }, expect.anything());
  });

  it('restores the count without a toast when the save of a first share fails', () => {
    actions.share(anEntry({ id: 7, isShared: false }));
    setStateQuietly.mock.calls[0][2]?.onStarted?.();

    setStateQuietly.mock.calls[0][2]?.onFailed?.(aProblem);

    expect(bumpCount).toHaveBeenLastCalledWith('shared', -1);
    expect(show).not.toHaveBeenCalled();
    expect(setState).not.toHaveBeenCalled();
  });

  it('leaves the count alone when the save of a re-share fails', () => {
    actions.share(anEntry({ id: 7, isShared: true }));
    setStateQuietly.mock.calls[0][2]?.onStarted?.();

    setStateQuietly.mock.calls[0][2]?.onFailed?.(aProblem);

    expect(bumpCount).not.toHaveBeenCalled();
  });
});

describe('EntryStateActions unsharing (#2007)', () => {
  const bumpCount = jest.fn();
  const setState = jest.fn();
  const selection = signal<{ kind: string }>({ kind: 'all' });
  let actions: EntryStateActions;

  beforeEach(() => {
    bumpCount.mockReset();
    setState.mockReset();
    selection.set({ kind: 'all' });
    actions = createActions({
      entries: { setState },
      subscriptions: { bumpCount },
      routeState: { openEntryId: signal(null), openEntry: signal(null), selection },
    });
  });

  it('uncounts the entry and clears isShared', () => {
    actions.unshare(anEntry({ id: 9, isShared: true }));
    setState.mock.calls[0][2]?.onStarted?.();

    expect(bumpCount).toHaveBeenCalledWith('shared', -1);
    expect(setState).toHaveBeenCalledWith(9, { isShared: false }, expect.anything());
    expect(TestBed.inject(ListContext).leavingIds().has(9)).toBe(false);
  });

  it('leaves the Shared list', () => {
    selection.set({ kind: 'shared' });

    actions.unshare(anEntry({ id: 9, isShared: true }));
    setState.mock.calls[0][2]?.onStarted?.();

    expect(TestBed.inject(ListContext).leavingIds().has(9)).toBe(true);
  });

  it('leaves the Shared list again on each attempt (#2066)', () => {
    selection.set({ kind: 'shared' });
    actions.unshare(anEntry({ id: 9, isShared: true }));
    const outcome = setState.mock.calls[0][2];

    outcome?.onStarted?.();
    outcome?.onFailed?.();
    outcome?.onStarted?.();

    expect(TestBed.inject(ListContext).leavingIds().has(9)).toBe(true);
  });

  it('restores the count and the row when the save fails', () => {
    selection.set({ kind: 'shared' });
    actions.unshare(anEntry({ id: 9, isShared: true }));
    setState.mock.calls[0][2]?.onStarted?.();

    setState.mock.calls[0][2]?.onFailed?.();

    expect(bumpCount).toHaveBeenLastCalledWith('shared', 1);
    expect(TestBed.inject(ListContext).leavingIds().has(9)).toBe(false);
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
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        { provide: EntryRowCache, useValue: {} },
        provideRouter([]),
        ...provideRouteListForTest({}),
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: LabelsStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId, openEntry: signal(null), selection: () => ({ kind: 'all' }) },
        },
        { provide: ToastService, useValue: {} },
        { provide: ArticlePostActions, useValue: { deletePost } },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  it('lets the row leave and recounts once the post is gone', () => {
    actions.deletePost(anEntry({ id: 7 }));

    expect(TestBed.inject(ListContext).leavingIds().has(7)).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('closes the article of the deleted post as Back does', () => {
    openEntryId.set(7);

    actions.deletePost(anEntry({ id: 7 }));

    expect(TestBed.inject(ListContext).leavingIds().has(7)).toBe(true);
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { entry: null, link: null } }),
    );
  });

  it('keeps the row when the post stays', () => {
    deletePost.mockReturnValue(EMPTY);

    actions.deletePost(anEntry({ id: 7 }));

    expect(TestBed.inject(ListContext).leavingIds().size).toBe(0);
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });
});

describe('EntryStateActions on a post deleted on its network (#1975)', () => {
  const show = jest.fn();
  const reloadCountsQuietly = jest.fn();
  const openEntryId = signal<number | null>(null);
  let navigate: jest.SpyInstance;

  beforeEach(() => {
    show.mockReset();
    reloadCountsQuietly.mockReset();
    openEntryId.set(null);
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        { provide: EntryRowCache, useValue: {} },
        provideRouter([]),
        ...provideRouteListForTest({}),
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: LabelsStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId, openEntry: signal(null), selection: () => ({ kind: 'all' }) },
        },
        { provide: ToastService, useValue: { show } },
        { provide: ArticlePostActions, useValue: {} },
      ],
    });
    TestBed.inject(EntryStateActions);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  it('says so, lets the row leave and closes its open article as Back does', () => {
    openEntryId.set(7);

    TestBed.inject(RemovedPosts).announce({ id: 7, network: 'bluesky' });

    expect(show).toHaveBeenCalledWith({ message: 'Deleted on Bluesky — removed here.' });
    expect(TestBed.inject(ListContext).leavingIds().has(7)).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { entry: null, link: null } }),
    );
  });

  it('leaves another open article alone', () => {
    openEntryId.set(8);

    TestBed.inject(RemovedPosts).announce({ id: 7, network: 'mastodon' });

    expect(show).toHaveBeenCalledWith({ message: 'Deleted on Mastodon — removed here.' });
    expect(TestBed.inject(ListContext).leavingIds().has(7)).toBe(true);
    expect(navigate).not.toHaveBeenCalled();
  });
});

describe('EntryStateActions voting (#2218)', () => {
  const setVote = jest.fn<Observable<void>, [EntryDto, VoteValue, ActOutcome]>(() => EMPTY);
  const refreshVote = jest.fn();
  const showProblem = jest.fn();
  const bumpCount = jest.fn();
  const openEntry = signal<EntryDto | null>(null);
  const selection = signal<Selection>({ kind: 'all', unread: false });
  let actions: EntryStateActions;

  const outcome = (call = 0): ActOutcome => setVote.mock.calls[call][2];
  const isLeaving = (id: number) => TestBed.inject(ListContext).leavingIds().has(id);

  beforeEach(() => {
    [setVote, refreshVote, showProblem, bumpCount].forEach((spy) => spy.mockClear());
    openEntry.set(null);
    selection.set({ kind: 'all', unread: false });
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        provideRouter([]),
        ...provideRouteListForTest({}, selection),
        { provide: EntryRowCache, useValue: { setVote, refreshVote } },
        { provide: SubscriptionsStore, useValue: { bumpCount } },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: LabelsStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry },
        },
        { provide: ToastService, useValue: { showProblem } },
        { provide: ArticlePostActions, useValue: {} },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
  });

  it('votesThroughTheStoreAndToastsAFailure', () => {
    const entry = anEntry({ score: 3, myVote: 0 });

    actions.vote(entry, -1);
    const problem = { title: 'Nope', status: 500, type: 'about:blank' };
    outcome().onFailed(problem);

    expect(setVote).toHaveBeenCalledWith(entry, -1, expect.anything());
    expect(showProblem).toHaveBeenCalledWith(problem);
    expect(bumpCount).not.toHaveBeenCalled();
  });

  it('counts an up-vote into Liked and an un-vote out, once each goes out (#2587)', () => {
    actions.vote(anEntry({ id: 1, score: 3, myVote: 0 }), 1);
    actions.vote(anEntry({ id: 2, score: 3, myVote: 1 }), 0);
    expect(bumpCount).not.toHaveBeenCalled();

    outcome(0).onStarted?.();
    outcome(1).onStarted?.();

    expect(bumpCount.mock.calls).toEqual([
      ['liked', 1],
      ['liked', -1],
    ]);
  });

  it('counts nothing for a vote that neither reaches nor leaves an up-vote (#2587)', () => {
    actions.vote(anEntry({ id: 1, score: 3, myVote: 0 }), -1);
    actions.vote(anEntry({ id: 2, score: 3, myVote: -1 }), 0);

    outcome(0).onStarted?.();
    outcome(1).onStarted?.();

    expect(bumpCount).not.toHaveBeenCalled();
  });

  it('counts a vote that replaces a local like out of Liked unless it is an up-vote (#2587)', () => {
    actions.vote(anEntry({ id: 1, score: 3, myVote: 0, isLiked: true }), 1);
    actions.vote(anEntry({ id: 2, score: 3, myVote: 0, isLiked: true }), -1);
    actions.vote(anEntry({ id: 3, score: 3, myVote: 1, isLiked: true }), 0);

    outcome(0).onStarted?.();
    outcome(1).onStarted?.();
    outcome(2).onStarted?.();

    expect(bumpCount.mock.calls).toEqual([
      ['liked', -1],
      ['liked', -1],
    ]);
  });

  it('takes the count back and toasts when the up-vote fails (#2587)', () => {
    actions.vote(anEntry({ id: 1, score: 3, myVote: 0 }), 1);

    outcome().onStarted?.();
    outcome().onFailed({ title: 'Nope', status: 500, type: 'about:blank' });

    expect(bumpCount.mock.calls).toEqual([
      ['liked', 1],
      ['liked', -1],
    ]);
    expect(showProblem).toHaveBeenCalled();
  });

  it('leaves the Liked list on a down-vote there and comes back when that fails (#2587)', () => {
    selection.set({ kind: 'liked' });
    actions.vote(anEntry({ id: 9, score: 3, myVote: 1 }), -1);

    outcome().onStarted?.();
    expect(isLeaving(9)).toBe(true);

    outcome().onFailed({ title: 'Nope', status: 500, type: 'about:blank' });
    expect(isLeaving(9)).toBe(false);
  });

  it('refreshesTheVoteOnceWhenAVotableEntryOpens', () => {
    openEntry.set(anEntry({ id: 4, score: 3, myVote: 0 }));
    TestBed.tick();

    expect(refreshVote).toHaveBeenCalledTimes(1);
  });

  it('doesNotRefreshAgainWhenTheSameEntryChangesItsVote', () => {
    openEntry.set(anEntry({ id: 4, score: 3, myVote: 0 }));
    TestBed.tick();

    openEntry.set(anEntry({ id: 4, score: 4, myVote: 1 }));
    TestBed.tick();

    expect(refreshVote).toHaveBeenCalledTimes(1);
  });

  it('refreshesAgainForAnotherEntry', () => {
    openEntry.set(anEntry({ id: 4, score: 3, myVote: 0 }));
    TestBed.tick();

    openEntry.set(anEntry({ id: 5, score: 3, myVote: 0 }));
    TestBed.tick();

    expect(refreshVote).toHaveBeenCalledTimes(2);
  });

  it('leavesAnEntryWithoutAVoteAlone', () => {
    openEntry.set(anEntry({ id: 4, myVote: null }));
    TestBed.tick();

    expect(refreshVote).not.toHaveBeenCalled();
  });
});

describe("EntryStateActions with a story's list over the reader's (#2329)", () => {
  const routeSetState = jest.fn();
  const storySetState = jest.fn();
  const bumpCount = jest.fn();
  let story: TestListContext;
  let actions: EntryStateActions;

  beforeEach(() => {
    routeSetState.mockReset();
    storySetState.mockReset();
    actions = createActions({
      entries: { setState: routeSetState },
      subscriptions: { bumpCount },
      routeState: {
        openEntryId: signal(null),
        openEntry: signal(null),
        selection: signal<Selection>({ kind: 'kept' }),
      },
    });
    story = new TestListContext(
      { setState: storySetState } as unknown as EntriesStore,
      signal<Selection>({ kind: 'trending-story', id: 42, unread: false }),
    );
    TestBed.inject(ListCover).coverWith(
      { context: story, heading: {} as ListHeading, open: signal(true) },
      TestBed.inject(DestroyRef),
    );
  });

  it("changes the row in the story's list, which it does not leave, over Kept", () => {
    actions.keep(anEntry({ id: 9, isKept: true }));
    storySetState.mock.calls[0][2]?.onStarted?.();

    expect(storySetState).toHaveBeenCalledWith(9, { isKept: false }, expect.anything());
    expect(routeSetState).not.toHaveBeenCalled();
    expect(story.leavingIds().has(9)).toBe(false);
    expect(TestBed.inject(ListContext).leavingIds().has(9)).toBe(false);
  });
});

describe('EntryStateActions labelling a row (#2581)', () => {
  const work: LabelPill = { id: 3, name: 'Work' };
  const home: LabelPill = { id: 4, name: 'Home' };
  let saved = new Subject<unknown>();
  const shownRow = jest.fn((): EntryDto | null => null);
  const attachLabel = jest.fn(() => saved.asObservable());
  const detachLabel = jest.fn(() => saved.asObservable());
  const labels = {
    reload: jest.fn(),
    attach: jest.fn(() => of(undefined)),
    detach: jest.fn(() => of(undefined)),
    create: jest.fn(() => of(work)),
  };
  const showProblem = jest.fn();
  const selection = signal<Selection>({ kind: 'all', unread: false });
  let actions: EntryStateActions;

  const isLeaving = (id: number) => TestBed.inject(ListContext).leavingIds().has(id);
  const failTheSave = () =>
    saved.error(new HttpErrorResponse({ status: 500, error: { title: 'Broken' } }));

  beforeEach(() => {
    jest.clearAllMocks();
    shownRow.mockReset();
    saved = new Subject<unknown>();
    selection.set({ kind: 'all', unread: false });
    actions = createActions({
      entries: {},
      subscriptions: {},
      routeState: { openEntryId: signal(null), openEntry: signal(null), selection },
      rows: { entry: shownRow, attachLabel, detachLabel },
      labels,
      toast: { showProblem },
    });
  });

  it('attaches through the row cache', () => {
    const entry = anEntry({ id: 9, labels: [work] });

    actions.attachLabel(entry, home);

    expect(attachLabel).toHaveBeenCalledWith(9, home, expect.anything());
    expect(labels.attach).toHaveBeenCalledWith(9, 4);
  });

  it('attaches nothing that is already on the row', () => {
    actions.attachLabel(anEntry({ id: 9, labels: [work] }), work);

    expect(attachLabel).not.toHaveBeenCalled();
  });

  it('detaches nothing the row does not carry', () => {
    actions.detachLabel(anEntry({ id: 9, labels: [home] }), work);

    expect(detachLabel).not.toHaveBeenCalled();
  });

  it('reads the row as shown, not the copy the caller holds', () => {
    shownRow.mockReturnValue(anEntry({ id: 9, labels: [work] }));
    const heldSinceTheMenuOpened = anEntry({ id: 9 });

    actions.attachLabel(heldSinceTheMenuOpened, work);
    actions.detachLabel(heldSinceTheMenuOpened, work);

    expect(attachLabel).not.toHaveBeenCalled();
    expect(detachLabel).toHaveBeenCalledWith(9, work, expect.anything());
  });

  it('drops the detached label through the row cache', () => {
    const entry = anEntry({ id: 9, labels: [home, work] });

    actions.detachLabel(entry, work);

    expect(detachLabel).toHaveBeenCalledWith(9, work, expect.anything());
    expect(labels.detach).toHaveBeenCalledWith(9, 3);
  });

  it('reloads the label tallies once the change is saved', () => {
    actions.attachLabel(anEntry({ id: 9 }), work);
    expect(labels.reload).not.toHaveBeenCalled();

    saved.next(undefined);

    expect(labels.reload).toHaveBeenCalledTimes(1);
  });

  it('leaves the favorites list of the label it detaches', () => {
    selection.set({ kind: 'favorites', label: 3 });

    actions.detachLabel(anEntry({ id: 9, isFavorite: true, labels: [work] }), work);

    expect(isLeaving(9)).toBe(true);
  });

  it('stays in the favorites list of a label it keeps', () => {
    selection.set({ kind: 'favorites', label: 3 });

    actions.detachLabel(anEntry({ id: 9, isFavorite: true, labels: [home, work] }), home);

    expect(isLeaving(9)).toBe(false);
  });

  it('leaves the unlabeled list with any label it gains', () => {
    selection.set({ kind: 'kept', label: 'unlabeled' });

    actions.attachLabel(anEntry({ id: 9, isKept: true }), home);

    expect(isLeaving(9)).toBe(true);
  });

  it('stays in a list no label narrows', () => {
    selection.set({ kind: 'kept' });

    actions.detachLabel(anEntry({ id: 9, isKept: true, labels: [work] }), work);

    expect(isLeaving(9)).toBe(false);
  });

  it('comes back and says why when the change fails', () => {
    selection.set({ kind: 'favorites', label: 3 });
    actions.detachLabel(anEntry({ id: 9, isFavorite: true, labels: [work] }), work);

    failTheSave();

    expect(isLeaving(9)).toBe(false);
    expect(showProblem).toHaveBeenCalledWith(expect.objectContaining({ status: 500 }));
    expect(labels.reload).not.toHaveBeenCalled();
  });

  it('creates a label and attaches it', () => {
    const entry = anEntry({ id: 9 });
    const done = jest.fn();

    actions.createLabel(entry, 'Work').subscribe({ complete: done });

    expect(labels.create).toHaveBeenCalledWith('Work');
    expect(attachLabel).toHaveBeenCalledWith(9, work, expect.anything());
    expect(done).toHaveBeenCalled();
  });

  it('errors with the problem when the label cannot be created, attaching nothing', () => {
    labels.create.mockReturnValueOnce(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: { type: 'label_name_taken', title: 'Taken', status: 409 },
          }),
      ),
    );
    const failed = jest.fn();

    actions.createLabel(anEntry({ id: 9 }), 'Work').subscribe({ error: failed });

    expect(failed).toHaveBeenCalledWith(expect.objectContaining({ type: 'label_name_taken' }));
    expect(attachLabel).not.toHaveBeenCalled();
  });
});

describe('EntryStateActions toggling several labels on one row (#2581)', () => {
  const work: LabelPill = { id: 3, name: 'Work' };
  const home: LabelPill = { id: 4, name: 'Home' };
  let cache: EntryRowCache;
  let actions: EntryStateActions;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        EntryRowCache,
        { provide: ReaderApi, useValue: {} },
        { provide: SocialPostApi, useValue: {} },
        {
          provide: LabelsStore,
          useValue: { reload: jest.fn(), attach: () => new Subject(), detach: () => new Subject() },
        },
        provideRouter([]),
        ...provideRouteListForTest({}),
        { provide: SubscriptionsStore, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: {} },
        { provide: ArticlePostActions, useValue: {} },
      ],
    });
    cache = TestBed.inject(EntryRowCache);
    actions = TestBed.inject(EntryStateActions);
  });

  it('shows both labels ticked from one stale copy of the row', () => {
    const stale = anEntry({ id: 9 });
    const held = cache.cached([stale]);
    cache.hold(held, new Set([9]));

    actions.attachLabel(stale, work);
    actions.attachLabel(stale, home);

    expect(cache.entry(9)?.labels).toEqual([home, work]);
  });
});

describe('EntryStateActions saving a flag the labels count (#2581)', () => {
  const setState = jest.fn();
  const reload = jest.fn();
  let actions: EntryStateActions;

  beforeEach(() => {
    jest.clearAllMocks();
    actions = createActions({
      entries: { setState },
      subscriptions: { bumpCount: jest.fn() },
      routeState: { openEntryId: signal(null), openEntry: signal(null) },
      labels: { reload },
    });
  });

  it('reloads the label tallies once an un-favorite is saved', () => {
    actions.favorite(anEntry({ id: 9, isFavorite: true }));
    expect(reload).not.toHaveBeenCalled();

    (setState.mock.calls[0][2] as StateSaveOutcome).onSaved?.();

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('reloads the label tallies once a keep is saved', () => {
    actions.keep(anEntry({ id: 9 }));

    (setState.mock.calls[0][2] as StateSaveOutcome).onSaved?.();

    expect(reload).toHaveBeenCalledTimes(1);
  });
});

describe('EntryStateActions moving an entry into and out of each mark (#2587)', () => {
  interface MoveHooks {
    onStarted?: () => void;
    onFailed?: (problem: Problem) => void;
  }
  const moves: MoveHooks[] = [];
  const capture = (outcome: MoveHooks) => {
    moves.push(outcome);
    return EMPTY;
  };
  const bumpCount = jest.fn();
  const selection = signal<Selection>({ kind: 'all', unread: false });
  let actions: EntryStateActions;

  const isLeaving = (id: number) => TestBed.inject(ListContext).leavingIds().has(id);
  const lastMove = () => moves[moves.length - 1];

  const cases: {
    mark: Mark;
    action: string;
    joins: (actions: EntryStateActions) => void;
    leaves: (actions: EntryStateActions) => void;
  }[] = [
    {
      mark: 'favorites',
      action: 'favorite',
      joins: (on) => on.favorite(anEntry({ id: 9 })),
      leaves: (on) => on.favorite(anEntry({ id: 9, isFavorite: true })),
    },
    {
      mark: 'kept',
      action: 'keep',
      joins: (on) => on.keep(anEntry({ id: 9 })),
      leaves: (on) => on.keep(anEntry({ id: 9, isKept: true })),
    },
    {
      mark: 'shared',
      action: 'share and unshare',
      joins: (on) => on.share(anEntry({ id: 9 })),
      leaves: (on) => on.unshare(anEntry({ id: 9, isShared: true })),
    },
    {
      mark: 'liked',
      action: 'like',
      joins: (on) => on.like(anEntry({ id: 9 })),
      leaves: (on) => on.like(anEntry({ id: 9, isLiked: true })),
    },
    {
      mark: 'liked',
      action: 'vote',
      joins: (on) => on.vote(anEntry({ id: 9, score: 3, myVote: 0 }), 1),
      leaves: (on) => on.vote(anEntry({ id: 9, score: 3, myVote: 1 }), 0),
    },
    {
      mark: 'viewed',
      action: 'toggleRead',
      joins: (on) => on.toggleRead(anEntry({ id: 9, isHidden: true })),
      leaves: (on) => on.toggleRead(anEntry({ id: 9, isViewed: true, isHidden: true })),
    },
  ];

  beforeEach(() => {
    moves.length = 0;
    bumpCount.mockReset();
    selection.set({ kind: 'all', unread: false });
    actions = createActions({
      entries: {
        setState: (_id: number, _patch: EntryStatePatch, outcome: MoveHooks) => capture(outcome),
        setStateQuietly: (_id: number, _patch: EntryStatePatch, outcome: MoveHooks) =>
          capture(outcome),
      },
      rows: {
        setLiked: (_entry: EntryDto, _isLiked: boolean, outcome: MoveHooks) => capture(outcome),
        setVote: (_entry: EntryDto, _to: VoteValue, outcome: MoveHooks) => capture(outcome),
      },
      subscriptions: { bumpCount },
      routeState: { openEntryId: signal(null), openEntry: signal(null), selection },
      toast: { showProblem: jest.fn() },
    });
  });

  it.each(cases)('$action counts the entry into $mark once it goes out', ({ mark, joins }) => {
    joins(actions);
    expect(bumpCount).not.toHaveBeenCalled();

    lastMove().onStarted?.();

    expect(bumpCount.mock.calls).toEqual([[mark, 1]]);
  });

  it.each(cases)('$action takes $mark back when joining fails', ({ mark, joins }) => {
    joins(actions);

    lastMove().onStarted?.();
    lastMove().onFailed?.(aProblem);

    expect(bumpCount.mock.calls).toEqual([
      [mark, 1],
      [mark, -1],
    ]);
  });

  it.each(cases)('$action leaves the $mark view and comes back on failure', ({ mark, leaves }) => {
    selection.set({ kind: mark } as Selection);
    leaves(actions);

    lastMove().onStarted?.();
    expect(bumpCount.mock.calls).toEqual([[mark, -1]]);
    expect(isLeaving(9)).toBe(true);

    lastMove().onFailed?.(aProblem);
    expect(bumpCount.mock.calls).toEqual([
      [mark, -1],
      [mark, 1],
    ]);
    expect(isLeaving(9)).toBe(false);
  });

  it("runs the caller's own start along with the move's", () => {
    const onStarted = jest.fn();
    const mover = actions as unknown as {
      buildMarkMove: (
        entry: EntryDto,
        patch: EntryStatePatch,
        outcome: StateSaveOutcome,
      ) => MoveHooks;
    };

    mover.buildMarkMove(anEntry({ id: 9 }), { isFavorite: true }, { onStarted }).onStarted?.();

    expect(bumpCount).toHaveBeenCalledWith('favorites', 1);
    expect(onStarted).toHaveBeenCalledTimes(1);
  });

  it.each(cases)('$action stays in a view of another kind', ({ leaves }) => {
    leaves(actions);

    lastMove().onStarted?.();

    expect(isLeaving(9)).toBe(false);
  });
});

describe('EntryStateActions retrying a failed favorite from the banner (#2587)', () => {
  const bumpCount = jest.fn();
  let store: EntriesStore;
  let ctrl: HttpTestingController;
  let actions: EntryStateActions;

  const failWith500 = (request: TestRequest) =>
    request.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
  const netFavorites = () =>
    bumpCount.mock.calls
      .filter(([mark]) => mark === 'favorites')
      .reduce((net, [, by]) => net + (by as number), 0);

  beforeEach(() => {
    bumpCount.mockReset();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        EntryStateActions,
        provideRouter([]),
        ListCover,
        { provide: ListContext, useFactory: () => new TestListContext(inject(EntriesStore)) },
        { provide: SubscriptionsStore, useValue: { bumpCount } },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: LabelsStore, useValue: { reload: jest.fn() } },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId: signal(null), openEntry: signal(null) },
        },
        { provide: ToastService, useValue: {} },
        { provide: ArticlePostActions, useValue: {} },
      ],
    });
    TestBed.inject(TokenStore).set('account-a.jwt');
    store = TestBed.inject(EntriesStore);
    ctrl = TestBed.inject(HttpTestingController);
    actions = TestBed.inject(EntryStateActions);
    store.load({ view: 'all' });
    ctrl
      .expectOne((request) => request.url === 'https://api.test/api/entries')
      .flush({ entries: [anEntry({ id: 1 })], nextCursor: null });
  });

  afterEach(() => localStorage.clear());

  it('counts the favorite once net when the retry succeeds', () => {
    actions.favorite(store.entries()[0]);
    failWith500(ctrl.expectOne('https://api.test/api/entries/1/state'));
    expect(netFavorites()).toBe(0);

    store.retry();
    ctrl.expectOne('https://api.test/api/entries/1/state').flush({ state: {} });

    expect(netFavorites()).toBe(1);
    expect(store.entries()[0].isFavorite).toBe(true);
  });
});
