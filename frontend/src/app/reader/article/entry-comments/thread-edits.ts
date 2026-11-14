import { EntryCommentDto } from '../../entry-comment';
import { steppedCounts } from '../optimistic-toggles';

/**
 * The thread with `reply` shown where a fresh load would put it: newest first, so at the top, or
 * right under the comment it answers, whose reply count steps up. A parent no longer shown drops it.
 */
export function withReply(
  comments: EntryCommentDto[],
  parentAddress: string | null,
  reply: EntryCommentDto,
): EntryCommentDto[] {
  if (parentAddress === null) return [{ ...reply, depth: 0 }, ...comments];
  const at = comments.findIndex((comment) => addressOf(comment) === parentAddress);
  const parent = comments[at];
  if (parent === undefined) return comments;
  return [
    ...comments.slice(0, at),
    withRepliesStepped(parent, true),
    { ...reply, depth: parent.depth + 1 },
    ...comments.slice(at + 1),
  ];
}

/** The thread without the comment at `address` and the replies under it; its parent counts one reply less. */
export function withoutReply(comments: EntryCommentDto[], address: string): EntryCommentDto[] {
  const at = comments.findIndex((comment) => addressOf(comment) === address);
  if (at === -1) return comments;
  const depth = comments[at].depth;
  const after = comments.slice(at + 1);
  const firstOutside = after.findIndex((comment) => comment.depth <= depth);
  const parentAt = parentIndexOf(comments, at);
  const before = comments
    .slice(0, at)
    .map((comment, index) => (index === parentAt ? withRepliesStepped(comment, false) : comment));
  return [...before, ...(firstOutside === -1 ? [] : after.slice(firstOutside))];
}

function addressOf(comment: EntryCommentDto): string | undefined {
  return comment.post?.address ?? comment.vote?.address;
}

function parentIndexOf(comments: EntryCommentDto[], at: number): number {
  for (let index = at - 1; index >= 0; index--) {
    if (comments[index].depth < comments[at].depth) return index;
  }
  return -1;
}

function withRepliesStepped(comment: EntryCommentDto, up: boolean): EntryCommentDto {
  if (comment.post == null) return comment;
  const counts = steppedCounts(comment.post.counts, 'replies', up);
  return { ...comment, post: { ...comment.post, counts } };
}
