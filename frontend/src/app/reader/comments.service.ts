import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { catchError, of } from 'rxjs';
import { onIdentityChange } from '../core/session-identity';
import { CommentsResponse, EntryCommentDto } from './models';
import { ReaderApi } from './reader-api';

const CACHE_CAP = 50;
const FRESH_MS = 60 * 60 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CommentsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; comments: EntryCommentDto[]; loadedAt: number }
  | { status: 'throttled'; retryAt: number }
  | { status: 'failed'; failedAt: number };

@Injectable({ providedIn: 'root' })
export class CommentsService {
  private readonly api = inject(ReaderApi);
  private readonly states = new Map<number, WritableSignal<CommentsState>>();
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(id: number): Signal<CommentsState> {
    return this.stateFor(id).asReadonly();
  }

  load(id: number): void {
    const state = this.stateFor(id);
    if (isStale(state())) this.fetch(id, state);
  }

  reload(id: number): void {
    const state = this.stateFor(id);
    if (state().status === 'loading') return;
    this.fetch(id, state);
  }

  private stateFor(id: number): WritableSignal<CommentsState> {
    const state = this.states.get(id) ?? signal<CommentsState>({ status: 'idle' });
    this.states.delete(id);
    this.states.set(id, state);
    const oldest = this.states.size > CACHE_CAP ? this.states.keys().next().value : undefined;
    if (oldest !== undefined) this.states.delete(oldest);
    return state;
  }

  private fetch(id: number, state: WritableSignal<CommentsState>): void {
    const generation = this.generation;
    state.set({ status: 'loading' });
    this.api
      .comments(id)
      .pipe(catchError(() => of({ status: 'failed' } as const)))
      .subscribe((response) => {
        if (generation === this.generation) state.set(settled(response));
      });
  }

  private clear(): void {
    this.generation++;
    this.states.clear();
  }
}

function isStale(state: CommentsState): boolean {
  switch (state.status) {
    case 'idle':
      return true;
    case 'ok':
      return Date.now() - state.loadedAt >= FRESH_MS;
    case 'throttled':
      return Date.now() >= state.retryAt;
    case 'failed':
      return Date.now() - state.failedAt >= FAILED_RETRY_MS;
    default:
      return false;
  }
}

function settled(response: CommentsResponse): CommentsState {
  switch (response.status) {
    case 'ok':
      return { status: 'ok', comments: response.comments, loadedAt: Date.now() };
    case 'throttled':
      return { status: 'throttled', retryAt: Date.now() + response.retryAfter * 1000 };
    default:
      return { status: 'failed', failedAt: Date.now() };
  }
}
