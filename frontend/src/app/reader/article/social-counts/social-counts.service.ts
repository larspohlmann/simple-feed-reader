import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Observable, catchError, forkJoin, of, throwError } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { HttpErrorResponse } from '@angular/common/http';
import { Problem, parseProblem } from '../../../core/problem';
import { OptimisticToggles, steppedCount } from '../optimistic-toggles';
import {
  PostActionsView,
  SOCIAL_POST_REMOVED,
  SocialCountsDto,
  SocialViewerDto,
  likeableSocial,
} from '../../entry-social';
import { EntryDto } from '../../models';
import { SocialPostApi } from '../../social-post-api';
import { EntriesStore } from '../../state/entries.store';
import { RemovedPosts } from '../../removed-posts.service';

const CACHE_CAP = 50;
const FRESH_MS = 120 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CountedEntry = Pick<EntryDto, 'id' | 'social'>;

/**
 * `likedAtLoad`: whether the loaded counts include the reader's like; the shown likes count from it.
 * `actions`: null when the network could not say, or the reader has no account on it.
 */
export type SocialCountsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | {
      status: 'ok';
      counts: SocialCountsDto;
      likedAtLoad: boolean;
      actions: PostActionsView | null;
      loadedAt: number;
    }
  | { status: 'failed'; failedAt: number };

@Injectable({ providedIn: 'root' })
export class SocialCountsService {
  private readonly api = inject(SocialPostApi);
  private readonly entries = inject(EntriesStore);
  private readonly removedPosts = inject(RemovedPosts);
  private readonly states = new Map<number, WritableSignal<SocialCountsState>>();
  private readonly toggles = new OptimisticToggles();
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(id: number): Signal<SocialCountsState> {
    return this.stateFor(id).asReadonly();
  }

  load(entry: CountedEntry): void {
    const state = this.stateFor(entry.id);
    if (isStale(state())) this.fetch(entry, state, this.api.counts(entry.id));
  }

  /** A reload waits out a repost on the wire, whose answer the reloaded counts might not include yet. */
  reload(entry: CountedEntry): void {
    const state = this.stateFor(entry.id);
    if (state().status === 'loading' || this.toggles.isBusy(entry.id)) return;
    this.fetch(entry, state, this.api.counts(entry.id, true));
  }

  /** Optimistic repost of the entry's post; dropped while one is on the wire or the network forbids it. */
  toggleRepost(entry: CountedEntry, onError: (problem: Problem) => void): void {
    const state = this.stateFor(entry.id);
    const current = state();
    if (current.status !== 'ok' || !current.actions?.canRepost) return;
    const isReposted = !current.actions.isReposted;
    this.toggles.run(entry.id, {
      key: 'repost',
      send: () => (isReposted ? this.api.repost(entry.id) : this.api.unrepost(entry.id)),
      to: isReposted,
      show: (reposted) => state.update((loaded) => withRepost(loaded, reposted)),
      onError,
    });
  }

  /** Counts a reply the reader published to the post, or one they deleted, before the network does. */
  stepReplies(entryId: number, up: boolean): void {
    this.stateFor(entryId).update((state) =>
      state.status === 'ok'
        ? {
            ...state,
            counts: { ...state.counts, replies: steppedCount(state.counts.replies, up) },
          }
        : state,
    );
  }

  stepQuotes(entryId: number): void {
    this.stateFor(entryId).update((state) =>
      state.status === 'ok' && state.counts.quotes !== null
        ? { ...state, counts: { ...state.counts, quotes: steppedCount(state.counts.quotes, true) } }
        : state,
    );
  }

  private stateFor(id: number): WritableSignal<SocialCountsState> {
    const state = this.states.get(id) ?? signal<SocialCountsState>({ status: 'idle' });
    this.states.delete(id);
    this.states.set(id, state);
    const oldest = this.states.size > CACHE_CAP ? this.states.keys().next().value : undefined;
    if (oldest !== undefined) this.states.delete(oldest);
    return state;
  }

  private fetch(
    entry: CountedEntry,
    state: WritableSignal<SocialCountsState>,
    counts$: Observable<SocialCountsDto>,
  ): void {
    const generation = this.generation;
    const likedBefore = entry.social?.isLiked ?? false;
    state.set({ status: 'loading' });
    forkJoin([counts$, this.networkViewer(entry)]).subscribe({
      next: ([counts, viewer]) => {
        if (generation !== this.generation) return;
        if (viewer !== null)
          this.entries.adoptLike(entry.id, { from: likedBefore, to: viewer.isLiked });
        state.set(loadedState(counts, viewer, likedBefore));
      },
      error: (error: unknown) => {
        if (generation !== this.generation) return;
        const network = entry.social?.network;
        if (network && isPostRemoved(error)) this.removedPosts.announce({ id: entry.id, network });
        state.set({ status: 'failed', failedAt: Date.now() });
      },
    });
  }

  /** What the network says the reader did to the post; null when it cannot say. */
  private networkViewer(entry: CountedEntry): Observable<SocialViewerDto | null> {
    if (!likeableSocial({ social: entry.social })) return of(null);
    return this.api
      .viewer(entry.id)
      .pipe(
        catchError((error: unknown) => (isPostRemoved(error) ? throwError(() => error) : of(null))),
      );
  }

  private clear(): void {
    this.generation++;
    this.states.clear();
    this.toggles.clear();
  }
}

function isStale(state: SocialCountsState): boolean {
  switch (state.status) {
    case 'idle':
      return true;
    case 'ok':
      return Date.now() - state.loadedAt >= FRESH_MS;
    case 'failed':
      return Date.now() - state.failedAt >= FAILED_RETRY_MS;
    default:
      return false;
  }
}

function loadedState(
  counts: SocialCountsDto,
  viewer: SocialViewerDto | null,
  likedBefore: boolean,
): SocialCountsState {
  return {
    status: 'ok',
    counts,
    likedAtLoad: viewer?.isLiked ?? likedBefore,
    actions: viewer && {
      isReposted: viewer.isReposted,
      canRepost: viewer.canRepost,
      canReply: viewer.canReply,
      canQuote: viewer.canQuote,
    },
    loadedAt: Date.now(),
  };
}

function isPostRemoved(error: unknown): boolean {
  return error instanceof HttpErrorResponse && parseProblem(error).type === SOCIAL_POST_REMOVED;
}

/** The loaded state with the reader's repost set, the reposts count following. */
function withRepost(state: SocialCountsState, isReposted: boolean): SocialCountsState {
  if (state.status !== 'ok' || !state.actions || state.actions.isReposted === isReposted)
    return state;
  return {
    ...state,
    counts: { ...state.counts, reposts: steppedCount(state.counts.reposts, isReposted) },
    actions: { ...state.actions, isReposted },
  };
}
