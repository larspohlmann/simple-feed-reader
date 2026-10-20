import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Observable, catchError, forkJoin, map, of } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { EntryLikeDto, SocialCountsDto, likeableSocial } from '../../entry-social';
import { EntryDto } from '../../models';
import { ReaderApi } from '../../reader-api';
import { EntriesStore } from '../../state/entries.store';

const CACHE_CAP = 50;
const FRESH_MS = 120 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CountedEntry = Pick<EntryDto, 'id' | 'social'>;

/** `likedAtLoad`: whether the reader's like was in the counts when they loaded: the network's word, else the entry's. */
export type SocialCountsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; counts: SocialCountsDto; likedAtLoad: boolean; loadedAt: number }
  | { status: 'failed'; failedAt: number };

@Injectable({ providedIn: 'root' })
export class SocialCountsService {
  private readonly api = inject(ReaderApi);
  private readonly entries = inject(EntriesStore);
  private readonly states = new Map<number, WritableSignal<SocialCountsState>>();
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
    forkJoin([counts$, this.networkLike(entry)]).subscribe({
      next: ([counts, networkLike]) => {
        if (generation !== this.generation) return;
        if (networkLike !== null)
          this.entries.adoptLike(entry.id, { from: likedBefore, to: networkLike });
        const likedAtLoad = networkLike ?? likedBefore;
        state.set({ status: 'ok', counts, likedAtLoad, loadedAt: Date.now() });
      },
      error: () => {
        if (generation === this.generation) state.set({ status: 'failed', failedAt: Date.now() });
      },
    });
  }

  /** Whether the network says the reader liked the post; null when it cannot say. */
  private networkLike(entry: CountedEntry): Observable<boolean | null> {
    if (!likeableSocial({ social: entry.social })) return of(null);
    return this.api.likeState(entry.id).pipe(
      map((like: EntryLikeDto) => like.isLiked),
      catchError(() => of(null)),
    );
  }

  private clear(): void {
    this.generation++;
    this.states.clear();
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
