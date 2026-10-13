import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { SocialCountsDto } from '../../entry-social';
import { EntryDto } from '../../models';
import { ReaderApi } from '../../reader-api';

const CACHE_CAP = 50;
const FRESH_MS = 120 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CountedEntry = Pick<EntryDto, 'id' | 'social'>;

/** `likedAtLoad`: the post's `isLiked` when its counts were asked for; the shown likes count from it. */
export type SocialCountsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; counts: SocialCountsDto; likedAtLoad: boolean; loadedAt: number }
  | { status: 'failed'; failedAt: number };

@Injectable({ providedIn: 'root' })
export class SocialCountsService {
  private readonly api = inject(ReaderApi);
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
    if (isStale(state())) this.fetch(entry, state);
  }

  private stateFor(id: number): WritableSignal<SocialCountsState> {
    const state = this.states.get(id) ?? signal<SocialCountsState>({ status: 'idle' });
    this.states.delete(id);
    this.states.set(id, state);
    const oldest = this.states.size > CACHE_CAP ? this.states.keys().next().value : undefined;
    if (oldest !== undefined) this.states.delete(oldest);
    return state;
  }

  private fetch(entry: CountedEntry, state: WritableSignal<SocialCountsState>): void {
    const generation = this.generation;
    const likedAtLoad = entry.social?.isLiked ?? false;
    state.set({ status: 'loading' });
    this.api.socialCounts(entry.id).subscribe({
      next: (counts) => {
        if (generation === this.generation) {
          state.set({ status: 'ok', counts, likedAtLoad, loadedAt: Date.now() });
        }
      },
      error: () => {
        if (generation === this.generation) state.set({ status: 'failed', failedAt: Date.now() });
      },
    });
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
