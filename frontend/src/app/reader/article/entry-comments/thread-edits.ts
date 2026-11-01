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
  const answered = {
    ...parent,
    post: {
      ...parent.post,
      counts: { ...parent.post.counts, replies: parent.post.counts.replies + 1 },
    },
  };
  return [
    ...comments.slice(0, at),
    answered,
    { ...reply, depth: parent.depth + 1 },
    ...comments.slice(at + 1),
  ];
}

/** The thread without the comment at `address` and the replies under it. */
export function withoutReply(comments: EntryCommentDto[], address: string): EntryCommentDto[] {
  const at = comments.findIndex((comment) => comment.post?.address === address);
  if (at === -1) return comments;
  const depth = comments[at].depth;
  const after = comments.slice(at + 1);
  const firstOutside = after.findIndex((comment) => comment.depth <= depth);
  return [...comments.slice(0, at), ...(firstOutside === -1 ? [] : after.slice(firstOutside))];
}
