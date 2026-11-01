import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, catchError, finalize, forkJoin, of } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { Problem, parseProblem } from '../../../core/problem';
import { RepostView, SocialCountsDto, SocialViewerDto, likeableSocial } from '../../entry-social';
import { EntryDto } from '../../models';
import { ReaderApi } from '../../reader-api';
import { EntriesStore } from '../../state/entries.store';

const CACHE_CAP = 50;
const FRESH_MS = 120 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CountedEntry = Pick<EntryDto, 'id' | 'social'>;

/**
 * `likedAtLoad`: whether the loaded counts include the reader's like; the shown likes count from it.
 * `repost`: null when the network could not say, or the reader has no account on it.
 */
export type SocialCountsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | {
      status: 'ok';
      counts: SocialCountsDto;
      likedAtLoad: boolean;
      repost: RepostView | null;
      loadedAt: number;
    }
  | { status: 'failed'; failedAt: number };

type LoadedCounts = Extract<SocialCountsState, { status: 'ok' }>;

@Injectable({ providedIn: 'root' })
export class SocialCountsService {
  private readonly api = inject(ReaderApi);
  private readonly entries = inject(EntriesStore);
  private readonly states = new Map<number, WritableSignal<SocialCountsState>>();
  private readonly repostsInFlight = new Set<number>();
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(id: number): Signal<SocialCountsState> {
    return this.stateFor(id).asReadonly();
  }

  load(entry: CountedEntry): void {
    const state = this.stateFor(entry.id);
    if (isStale(state())) this.fetch(entry, state, this.api.socialCounts(entry.id));
  }

  reload(entry: CountedEntry): void {
    const state = this.stateFor(entry.id);
    if (state().status === 'loading') return;
    this.fetch(entry, state, this.api.socialCounts(entry.id, true));
  }

  /** Optimistic repost of the entry's post; dropped while one is on the wire or the network forbids it. */
  toggleRepost(entry: CountedEntry, onError: (problem: Problem) => void): void {
    const state = this.stateFor(entry.id);
    const current = state();
    if (current.status !== 'ok' || !current.repost?.canRepost) return;
    if (this.repostsInFlight.has(entry.id)) return;
    const isReposted = !current.repost.isReposted;
    const request$ = isReposted ? this.api.repost(entry.id) : this.api.unrepost(entry.id);
    this.repostsInFlight.add(entry.id);
    state.update((loaded) => withRepost(loaded, isReposted));
    request$.pipe(finalize(() => this.repostsInFlight.delete(entry.id))).subscribe({
      error: (error: HttpErrorResponse) => {
        state.update((loaded) => withRepost(loaded, !isReposted));
        onError(parseProblem(error));
      },
    });
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
        state.set({
          status: 'ok',
          counts,
          likedAtLoad: viewer?.isLiked ?? likedBefore,
          repost: viewer && { isReposted: viewer.isReposted, canRepost: viewer.canRepost },
          loadedAt: Date.now(),
        });
      },
      error: () => {
        if (generation === this.generation) state.set({ status: 'failed', failedAt: Date.now() });
      },
    });
  }

  /** What the network says the reader did to the post; null when it cannot say. */
  private networkViewer(entry: CountedEntry): Observable<SocialViewerDto | null> {
    if (!likeableSocial({ social: entry.social })) return of(null);
    return this.api.socialViewer(entry.id).pipe(catchError(() => of(null)));
  }

  private clear(): void {
    this.generation++;
    this.states.clear();
    this.repostsInFlight.clear();
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

/** The loaded state with the reader's repost set, the reposts count following. */
function withRepost(state: SocialCountsState, isReposted: boolean): SocialCountsState {
  if (state.status !== 'ok' || !state.repost || state.repost.isReposted === isReposted) return state;
  const reposts = Math.max(0, state.counts.reposts + (isReposted ? 1 : -1));
  const loaded: LoadedCounts = {
    ...state,
    counts: { ...state.counts, reposts },
    repost: { ...state.repost, isReposted },
  };
  return loaded;
}
