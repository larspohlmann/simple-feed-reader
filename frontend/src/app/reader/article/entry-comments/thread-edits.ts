import { EntryCommentDto } from '../../entry-comment';

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
  const at = comments.findIndex((comment) => comment.post?.address === parentAddress);
  const parent = comments[at];
  if (parent?.post == null) return comments;
  return [
    ...comments.slice(0, at),
    withRepliesStepped(parent, 1),
    { ...reply, depth: parent.depth + 1 },
    ...comments.slice(at + 1),
  ];
}

/** The thread without the comment at `address` and the replies under it; its parent counts one reply less. */
export function withoutReply(comments: EntryCommentDto[], address: string): EntryCommentDto[] {
  const at = comments.findIndex((comment) => comment.post?.address === address);
  if (at === -1) return comments;
  const depth = comments[at].depth;
  const after = comments.slice(at + 1);
  const firstOutside = after.findIndex((comment) => comment.depth <= depth);
  const parentAt = parentIndexOf(comments, at);
  const before = comments
    .slice(0, at)
    .map((comment, index) => (index === parentAt ? withRepliesStepped(comment, -1) : comment));
  return [...before, ...(firstOutside === -1 ? [] : after.slice(firstOutside))];
}

function parentIndexOf(comments: EntryCommentDto[], at: number): number {
  for (let index = at - 1; index >= 0; index--) {
    if (comments[index].depth < comments[at].depth) return index;
  }
  return -1;
}

function withRepliesStepped(comment: EntryCommentDto, step: number): EntryCommentDto {
  if (comment.post == null) return comment;
  const replies = Math.max(0, comment.post.counts.replies + step);
  return { ...comment, post: { ...comment.post, counts: { ...comment.post.counts, replies } } };
}
