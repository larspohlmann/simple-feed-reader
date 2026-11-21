import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { EMPTY, Observable, catchError, of, tap } from 'rxjs';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { ToastService } from '../../../shared/toast/toast.service';
import { OptimisticToggles, steppedCounts } from '../optimistic-toggles';
import { CommentsResponse, EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { SocialCountsDto } from '../../entry-social';
import { EntryDto } from '../../models';
import { ReaderApi } from '../../reader-api';
import { SocialPostApi, VoteAnswer } from '../../social-post-api';
import { VoteValue } from '../../vote-pill/vote';
import { ItemRef, buildItemKey } from '../../state/item-key';
import { CommentTotals } from '../../state/comment-totals';
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

/** How many comments an entry has: the loaded thread's total once it is in, the stored count before. */
export function commentTotal(
  entry: Pick<EntryDto, 'comments' | 'commentCount'>,
  state: CommentsState,
): number | null {
  if (!entry.comments) return null;
  return state.status === 'ok' ? threadTotal(state) : entry.commentCount;
}

type LoadedThread = Extract<CommentsState, { status: 'ok' }>;

@Injectable({ providedIn: 'root' })
export class CommentsService {
  private readonly api = inject(ReaderApi);
  private readonly socialPosts = inject(SocialPostApi);
  private readonly states = new Map<string, WritableSignal<CommentsState>>();
  private readonly totals = inject(CommentTotals);
  private readonly toggles = new OptimisticToggles(inject(ToastService));
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(item: ItemRef): Signal<CommentsState> {
    return this.stateFor(item).asReadonly();
  }

  load(item: ItemRef): void {
    if (isStale(this.stateFor(item)())) this.fetch(item, this.api.comments(item.id));
  }

  /** A reload waits out a like or repost on the wire, whose answer the reloaded thread might not include yet. */
  reload(item: ItemRef): void {
    const state = this.stateFor(item);
    if (state().status === 'loading' || this.toggles.isBusy(buildItemKey(item))) return;
    this.fetch(item, this.api.comments(item.id, true));
  }

  /** Optimistic like of one comment; dropped while a like of the same comment is on the wire. */
  toggleLike(item: ItemRef, post: EntryCommentPostDto): Observable<void> {
    return this.toggle(item, post, 'isLiked');
  }

  /** Optimistic repost of one comment; dropped while one is on the wire or the network forbids it. */
  toggleRepost(item: ItemRef, post: EntryCommentPostDto): void {
    if (post.canRepost) this.toggle(item, post, 'isReposted');
  }

  /** The vote shows at once; its score waits for the answer. */
  vote(item: ItemRef, comment: EntryCommentDto, to: VoteValue): Observable<void> {
    if (comment.vote === null) return EMPTY;
    const { address, myVote: from } = comment.vote;
    const score = comment.score ?? 0;
    const state = this.stateFor(item);
    const show = (shown: VoteAnswer) =>
      state.update((current) => withVote(current, address, shown));
    return this.toggles.change(buildItemKey(item), {
      key: `vote ${address}`,
      send: () => this.socialPosts.voteComment(item.id, address, to).pipe(tap(show)),
      apply: () => show({ myVote: to, score }),
      revert: () => show({ myVote: from, score }),
    });
  }

  /** Shows a reply the reader just published, until the next load brings it from the network. */
  insertReply(item: ItemRef, parentAddress: string | null, reply: EntryCommentDto): void {
    this.changeThread(item, (thread) => ({
      ...thread,
      comments: withReply(thread.comments, parentAddress, reply),
    }));
  }

  removeReply(item: ItemRef, address: string): void {
    this.changeThread(item, (thread) => ({
      ...thread,
      comments: withoutReply(thread.comments, address),
    }));
  }

  countQuote(item: ItemRef, address: string): void {
    this.stateFor(item).update((state) => withQuote(state, address));
  }

  private toggle(item: ItemRef, post: EntryCommentPostDto, flag: PostFlag): Observable<void> {
    const { address } = post;
    const to = !post[flag];
    const state = this.stateFor(item);
    const { set, unset } = POST_FLAGS[flag];
    return this.toggles.run(buildItemKey(item), {
      key: `${flag} ${address}`,
      send: () => this.socialPosts[to ? set : unset](item.id, address),
      to,
      show: (value) =>
        state.update((current) =>
          withPost(current, address, (shown) => flagged(shown, flag, value)),
        ),
    });
  }

  private stateFor(item: ItemRef): WritableSignal<CommentsState> {
    const key = buildItemKey(item);
    const state = this.states.get(key) ?? signal<CommentsState>({ status: 'idle' });
    this.states.delete(key);
    this.states.set(key, state);
    const oldest = this.states.size > CACHE_CAP ? this.states.keys().next().value : undefined;
    if (oldest !== undefined) this.states.delete(oldest);
    return state;
  }

  private changeThread(item: ItemRef, change: (thread: LoadedThread) => LoadedThread): void {
    const state = this.stateFor(item);
    const current = state();
    if (current.status !== 'ok') return;
    const changed = change(current);
    state.set(changed);
    this.totals.record(item, threadTotal(changed));
  }

  private fetch(item: ItemRef, request: Observable<CommentsResponse>): void {
    const state = this.stateFor(item);
    const generation = this.generation;
    state.set({ status: 'loading' });
    request.pipe(catchError(() => of({ status: 'failed' } as const))).subscribe((response) => {
      if (generation !== this.generation) return;
      const next = settled(response);
      state.set(next);
      if (next.status === 'ok') this.totals.record(item, threadTotal(next));
    });
  }

  private clear(): void {
    this.generation++;
    this.states.clear();
    this.toggles.clear();
  }
}

function threadTotal(thread: LoadedThread): number {
  return thread.comments.length + thread.omitted;
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
