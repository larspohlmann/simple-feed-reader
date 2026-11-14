import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { Observable, catchError, of, tap } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { ToastService } from '../../../shared/toast/toast.service';
import { OptimisticToggles, steppedCounts } from '../optimistic-toggles';
import { CommentsResponse, EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { SocialCountsDto } from '../../entry-social';
import { ReaderApi } from '../../reader-api';
import { SocialPostApi, VoteAnswer } from '../../social-post-api';
import { VoteValue, steppedScore } from '../../vote-pill/vote';
import { withReply, withoutReply } from './thread-edits';

const CACHE_CAP = 50;
const FRESH_MS = 60 * 60 * 1000;
const FAILED_RETRY_MS = 60 * 1000;

/** A flag the reader toggles on a comment: the count it moves, and the requests that set and unset it. */
const POST_FLAGS = {
  isLiked: { count: 'likes', set: 'likeComment', unset: 'unlikeComment' },
  isReposted: { count: 'reposts', set: 'repostComment', unset: 'unrepostComment' },
} as const satisfies Record<
  string,
  { count: keyof SocialCountsDto; set: keyof SocialPostApi; unset: keyof SocialPostApi }
>;

type PostFlag = keyof typeof POST_FLAGS;

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
  private readonly toggles = new OptimisticToggles(inject(ToastService));
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
  toggleLike(entryId: number, post: EntryCommentPostDto): void {
    this.toggle(entryId, post, 'isLiked');
  }

  /** Optimistic repost of one comment; dropped while one is on the wire or the network forbids it. */
  toggleRepost(entryId: number, post: EntryCommentPostDto): void {
    if (post.canRepost) this.toggle(entryId, post, 'isReposted');
  }

  /** Optimistic vote on one Lemmy comment, its score stepping; dropped while a vote on the same comment is on the wire. */
  vote(entryId: number, comment: EntryCommentDto, to: VoteValue): void {
    if (comment.vote === null) return;
    const { address, myVote: from } = comment.vote;
    const score = comment.score ?? 0;
    const state = this.stateFor(entryId);
    const show = (shown: VoteAnswer) =>
      state.update((current) => withVote(current, address, shown));
    this.toggles.run(entryId, {
      key: `vote ${address}`,
      send: () => this.socialPosts.voteComment(entryId, address, to).pipe(tap(show)),
      to: true,
      show: (applied) =>
        applied
          ? show({ myVote: to, score: steppedScore(score, from, to) })
          : show({ myVote: from, score }),
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

  private toggle(entryId: number, post: EntryCommentPostDto, flag: PostFlag): void {
    const { address } = post;
    const to = !post[flag];
    const state = this.stateFor(entryId);
    const { set, unset } = POST_FLAGS[flag];
    this.toggles.run(entryId, {
      key: `${flag} ${address}`,
      send: () => this.socialPosts[to ? set : unset](entryId, address),
      to,
      show: (value) =>
        state.update((current) =>
          withPost(current, address, (shown) => flagged(shown, flag, value)),
        ),
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

/** The post with `flag` set to `value`, the flag's count following. */
function flagged(post: EntryCommentPostDto, flag: PostFlag, value: boolean): EntryCommentPostDto {
  return post[flag] === value
    ? post
    : { ...post, [flag]: value, counts: steppedCounts(post.counts, POST_FLAGS[flag].count, value) };
}

/** The state with one more quote counted on the comment at `address`, where its network counts quotes. */
function withQuote(state: CommentsState, address: string): CommentsState {
  return withPost(state, address, (post) => {
    const counts = steppedCounts(post.counts, 'quotes', true);
    return counts === post.counts ? post : { ...post, counts };
  });
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

function withVote(state: CommentsState, address: string, shown: VoteAnswer): CommentsState {
  if (state.status !== 'ok') return state;
  const comments = state.comments.map((comment) =>
    comment.vote?.address === address
      ? { ...comment, score: shown.score, vote: { ...comment.vote, myVote: shown.myVote } }
      : comment,
  );
  return { ...state, comments };
}
