import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, catchError, finalize, of } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { Problem, parseProblem } from '../../../core/problem';
import { CommentsResponse, EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { ReaderApi } from '../../reader-api';
import { SocialPostApi } from '../../social-post-api';

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

/** A flag on one comment set at once and put back if what `send` sends fails. */
interface OptimisticChange {
  key: string;
  send: () => Observable<unknown>;
  to: boolean;
  apply: (state: CommentsState, value: boolean) => CommentsState;
  onError: (problem: Problem) => void;
}

@Injectable({ providedIn: 'root' })
export class CommentsService {
  private readonly api = inject(ReaderApi);
  private readonly socialPosts = inject(SocialPostApi);
  private readonly states = new Map<number, WritableSignal<CommentsState>>();
  private readonly actionsInFlight = new Set<string>();
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
    this.optimistic(entryId, {
      key: `like ${address}`,
      send: () =>
        isLiked
          ? this.socialPosts.likeComment(entryId, address)
          : this.socialPosts.unlikeComment(entryId, address),
      to: isLiked,
      apply: (state, liked) => withLike(state, address, liked),
      onError,
    });
  }

  /** Optimistic repost of one comment; dropped while one is on the wire or the network forbids it. */
  toggleRepost(
    entryId: number,
    post: EntryCommentPostDto,
    onError: (problem: Problem) => void,
  ): void {
    if (!post.canRepost) return;
    const { address } = post;
    const isReposted = !post.isReposted;
    this.optimistic(entryId, {
      key: `repost ${address}`,
      send: () =>
        isReposted
          ? this.socialPosts.repostComment(entryId, address)
          : this.socialPosts.unrepostComment(entryId, address),
      to: isReposted,
      apply: (state, reposted) => withRepost(state, address, reposted),
      onError,
    });
  }

  private optimistic(entryId: number, change: OptimisticChange): void {
    const key = `${entryId} ${change.key}`;
    if (this.actionsInFlight.has(key)) return;
    const state = this.stateFor(entryId);
    this.actionsInFlight.add(key);
    state.update((current) => change.apply(current, change.to));
    change
      .send()
      .pipe(finalize(() => this.actionsInFlight.delete(key)))
      .subscribe({
        error: (error: HttpErrorResponse) => {
          state.update((current) => change.apply(current, !change.to));
          change.onError(parseProblem(error));
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
    this.actionsInFlight.clear();
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
  return withPost(state, address, (post) =>
    post.isLiked === isLiked
      ? post
      : { ...post, isLiked, counts: { ...post.counts, likes: moved(post.counts.likes, isLiked) } },
  );
}

/** The state with the comment at `address` reposted or not, its repost count following. */
function withRepost(state: CommentsState, address: string, isReposted: boolean): CommentsState {
  return withPost(state, address, (post) =>
    post.isReposted === isReposted
      ? post
      : {
          ...post,
          isReposted,
          counts: { ...post.counts, reposts: moved(post.counts.reposts, isReposted) },
        },
  );
}

function withPost(
  state: CommentsState,
  address: string,
  change: (post: EntryCommentPostDto) => EntryCommentPostDto,
): CommentsState {
  if (state.status !== 'ok') return state;
  const comments = state.comments.map((comment) => {
    const post = comment.post;
    if (post?.address !== address) return comment;
    const changed = change(post);
    return changed === post ? comment : { ...comment, post: changed };
  });
  return { ...state, comments };
}

function moved(count: number, up: boolean): number {
  return Math.max(0, count + (up ? 1 : -1));
}
