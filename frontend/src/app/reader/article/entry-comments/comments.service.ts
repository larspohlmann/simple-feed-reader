import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Observable, catchError, of } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { Problem } from '../../../core/problem';
import { OptimisticToggles, steppedCount } from '../optimistic-toggles';
import { CommentsResponse, EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { ReaderApi } from '../../reader-api';
import { SocialPostApi } from '../../social-post-api';
import { withReply, withoutReply } from './thread-edits';

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
  private readonly socialPosts = inject(SocialPostApi);
  private readonly states = new Map<number, WritableSignal<CommentsState>>();
  private readonly toggles = new OptimisticToggles();
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

  /** A reload waits out a like or repost on the wire, whose answer the reloaded thread might not include yet. */
  reload(id: number): void {
    const state = this.stateFor(id);
    if (state().status === 'loading' || this.toggles.isBusy(id)) return;
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
    const state = this.stateFor(entryId);
    this.toggles.run(entryId, {
      key: `like ${address}`,
      send: () =>
        isLiked
          ? this.socialPosts.likeComment(entryId, address)
          : this.socialPosts.unlikeComment(entryId, address),
      to: isLiked,
      show: (liked) => state.update((current) => withLike(current, address, liked)),
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
    const state = this.stateFor(entryId);
    this.toggles.run(entryId, {
      key: `repost ${address}`,
      send: () =>
        isReposted
          ? this.socialPosts.repostComment(entryId, address)
          : this.socialPosts.unrepostComment(entryId, address),
      to: isReposted,
      show: (reposted) => state.update((current) => withRepost(current, address, reposted)),
      onError,
    });
  }

  /** Shows a reply the reader just published, until the next load brings it from the network. */
  insertReply(entryId: number, parentAddress: string | null, reply: EntryCommentDto): void {
    this.stateFor(entryId).update((state) =>
      state.status === 'ok'
        ? { ...state, comments: withReply(state.comments, parentAddress, reply) }
        : state,
    );
  }

  removeReply(entryId: number, address: string): void {
    this.stateFor(entryId).update((state) =>
      state.status === 'ok' ? { ...state, comments: withoutReply(state.comments, address) } : state,
    );
  }

  countQuote(entryId: number, address: string): void {
    this.stateFor(entryId).update((state) => withQuote(state, address));
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
    this.toggles.clear();
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
      : {
          ...post,
          isLiked,
          counts: { ...post.counts, likes: steppedCount(post.counts.likes, isLiked) },
        },
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
          counts: { ...post.counts, reposts: steppedCount(post.counts.reposts, isReposted) },
        },
  );
}

/** The state with one more quote counted on the comment at `address`, where its network counts quotes. */
function withQuote(state: CommentsState, address: string): CommentsState {
  return withPost(state, address, (post) =>
    post.counts.quotes === null
      ? post
      : { ...post, counts: { ...post.counts, quotes: steppedCount(post.counts.quotes, true) } },
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
