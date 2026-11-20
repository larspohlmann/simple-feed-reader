import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Observable, catchError, forkJoin, of, tap, throwError } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from '../../../core/problem';
import { ToastService } from '../../../shared/toast/toast.service';
import { OptimisticToggles, steppedCounts } from '../optimistic-toggles';
import {
  PostActionsView,
  SOCIAL_POST_REMOVED,
  SocialCountsDto,
  SocialViewerDto,
  likeableSocial,
} from '../../entry-social';
import { EntryDto } from '../../models';
import { SocialPostApi } from '../../social-post-api';
import { ItemRef, buildItemKey } from '../../state/item-key';
import { EntryRowCache } from '../../state/entry-row-cache';
import { RemovedPosts } from '../../removed-posts.service';
import { NetworkShares } from '../share/network-shares.service';

const CACHE_CAP = 50;
const FRESH_MS = 120 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CountedEntry = Pick<EntryDto, 'id' | 'canonicalItemId' | 'social'>;

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
  private readonly rows = inject(EntryRowCache);
  private readonly removedPosts = inject(RemovedPosts);
  private readonly shares = inject(NetworkShares);
  private readonly states = new Map<string, WritableSignal<SocialCountsState>>();
  private readonly toggles = new OptimisticToggles(inject(ToastService));
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(item: ItemRef): Signal<SocialCountsState> {
    return this.stateFor(item).asReadonly();
  }

  load(entry: CountedEntry): void {
    const state = this.stateFor(entry);
    if (isStale(state())) this.fetch(entry, state, this.api.counts(entry.id));
  }

  /** A reload waits out a repost on the wire, whose answer the reloaded counts might not include yet. */
  reload(entry: CountedEntry): void {
    const state = this.stateFor(entry);
    if (state().status === 'loading' || this.toggles.isBusy(buildItemKey(entry))) return;
    this.fetch(entry, state, this.api.counts(entry.id, true));
  }

  /** Optimistic repost of the entry's post; dropped while one is on the wire or the network forbids it. */
  toggleRepost(entry: CountedEntry): void {
    const state = this.stateFor(entry);
    const current = state();
    if (current.status !== 'ok' || !current.actions?.canRepost) return;
    const isReposted = !current.actions.isReposted;
    this.toggles.run(buildItemKey(entry), {
      key: 'repost',
      send: () => (isReposted ? this.repost(entry.id) : this.api.unrepost(entry.id)),
      to: isReposted,
      show: (reposted) => state.update((loaded) => withRepost(loaded, reposted)),
    });
  }

  /** Counts a reply the reader published to the post, or one they deleted, before the network does. */
  stepReplies(item: ItemRef, up: boolean): void {
    this.stepCount(item, 'replies', up);
  }

  stepQuotes(item: ItemRef): void {
    this.stepCount(item, 'quotes', true);
  }

  private stepCount(item: ItemRef, kind: keyof SocialCountsDto, up: boolean): void {
    this.stateFor(item).update((state) => {
      if (state.status !== 'ok') return state;
      const counts = steppedCounts(state.counts, kind, up);
      return counts === state.counts ? state : { ...state, counts };
    });
  }

  private repost(entryId: number): Observable<unknown> {
    return this.api.repost(entryId).pipe(tap(() => this.shares.reflect(entryId)));
  }

  private stateFor(item: ItemRef): WritableSignal<SocialCountsState> {
    const key = buildItemKey(item);
    const state = this.states.get(key) ?? signal<SocialCountsState>({ status: 'idle' });
    this.states.delete(key);
    this.states.set(key, state);
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
          this.rows.adoptLike(entry.id, { from: likedBefore, to: viewer.isLiked });
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
    counts: steppedCounts(state.counts, 'reposts', isReposted),
    actions: { ...state.actions, isReposted },
  };
}
