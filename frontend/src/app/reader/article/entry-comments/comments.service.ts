import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, catchError, finalize, of } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { Problem, parseProblem } from '../../../core/problem';
import { CommentsResponse, EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { ReaderApi } from '../../reader-api';

const CACHE_CAP = 50;
const FRESH_MS = 60 * 60 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

export type CommentsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | {
      status: 'ok';
      comments: EntryCommentDto[];
      omitted: number;
      fetchedAt: string;
      loadedAt: number;
    }
  | { status: 'throttled'; retryAt: number }
  | { status: 'failed'; failedAt: number };

@Injectable({ providedIn: 'root' })
export class CommentsService {
  private readonly api = inject(ReaderApi);
  private readonly states = new Map<number, WritableSignal<CommentsState>>();
  private readonly likesInFlight = new Set<string>();
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(id: number): Signal<CommentsState> {
    return this.stateFor(id).asReadonly();
  }

  load(id: number): void {
    const state = this.stateFor(id);
    if (isStale(state())) this.fetch(state, this.api.comments(id));
  }

  reload(id: number): void {
    const state = this.stateFor(id);
    if (state().status === 'loading') return;
    this.fetch(state, this.api.comments(id, true));
  }

  /** Optimistic like of one comment; dropped while a like of the same comment is on the wire. */
  toggleLike(
    entryId: number,
    post: EntryCommentPostDto,
    onError: (problem: Problem) => void,
  ): void {
    const { address } = post;
    const isLiked = !post.isLiked;
    const key = `${entryId} ${address}`;
    if (this.likesInFlight.has(key)) return;
    const state = this.stateFor(entryId);
    const request$ = isLiked
      ? this.api.likeComment(entryId, address)
      : this.api.unlikeComment(entryId, address);
    this.likesInFlight.add(key);
    state.update((current) => withLike(current, address, isLiked));
    request$.pipe(finalize(() => this.likesInFlight.delete(key))).subscribe({
      error: (error: HttpErrorResponse) => {
        state.update((current) => withLike(current, address, !isLiked));
        onError(parseProblem(error));
      },
    });
  }

  private stateFor(id: number): WritableSignal<CommentsState> {
    const state = this.states.get(id) ?? signal<CommentsState>({ status: 'idle' });
    this.states.delete(id);
    this.states.set(id, state);
    const oldest = this.states.size > CACHE_CAP ? this.states.keys().next().value : undefined;
    if (oldest !== undefined) this.states.delete(oldest);
    return state;
  }

  private fetch(state: WritableSignal<CommentsState>, request: Observable<CommentsResponse>): void {
    const generation = this.generation;
    state.set({ status: 'loading' });
    request.pipe(catchError(() => of({ status: 'failed' } as const))).subscribe((response) => {
      if (generation === this.generation) state.set(settled(response));
    });
  }

  private clear(): void {
    this.generation++;
    this.states.clear();
    this.likesInFlight.clear();
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
      return {
        status: 'ok',
        comments: response.comments,
        omitted: response.omitted,
        fetchedAt: response.fetchedAt,
        loadedAt: Date.now(),
      };
    case 'throttled':
      return { status: 'throttled', retryAt: Date.now() + response.retryAfter * 1000 };
    default:
      return { status: 'failed', failedAt: Date.now() };
  }
}

/** The state with the comment at `address` liked or not, its like count following. */
function withLike(state: CommentsState, address: string, isLiked: boolean): CommentsState {
  if (state.status !== 'ok') return state;
  const comments = state.comments.map((comment) => {
    const post = comment.post;
    if (post?.address !== address || post.isLiked === isLiked) return comment;
    const likes = Math.max(0, post.counts.likes + (isLiked ? 1 : -1));
    return { ...comment, post: { ...post, isLiked, counts: { ...post.counts, likes } } };
  });
  return { ...state, comments };
}
