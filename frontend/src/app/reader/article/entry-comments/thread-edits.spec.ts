import { aCommentPost, anEntryComment } from '../../../../testing/entry-testing';
import { EntryCommentDto } from '../../entry-comment';
import { withQuoteCounted, withReply, withoutReply } from './thread-edits';

const commentAt = (address: string, depth: number, replies = 0): EntryCommentDto =>
  anEntryComment({
    html: address,
    depth,
    post: aCommentPost({ address, counts: { likes: 0, reposts: 0, replies, quotes: null } }),
  });

const shape = (comments: EntryCommentDto[]) =>
  comments.map((comment) => `${comment.html}@${comment.depth}`);

describe('thread edits', () => {
  const thread = [commentAt('a', 0, 1), commentAt('a1', 1), commentAt('b', 0)];

  it('puts a reply to the post at the top', () => {
    expect(shape(withReply(thread, null, commentAt('new', 3)))).toEqual([
      'new@0',
      'a@0',
      'a1@1',
      'b@0',
    ]);
  });

  it('puts a reply to a comment right under it, one level deeper, and counts it there', () => {
    const edited = withReply(thread, 'a', commentAt('new', 0));

    expect(shape(edited)).toEqual(['a@0', 'new@1', 'a1@1', 'b@0']);
    expect(edited[0].post?.counts.replies).toBe(2);
  });

  it('drops a reply whose parent is no longer shown', () => {
    expect(withReply(thread, 'gone', commentAt('new', 0))).toBe(thread);
  });

  it('removes a comment with the replies under it, and nothing else', () => {
    expect(shape(withoutReply(thread, 'a'))).toEqual(['b@0']);
    expect(shape(withoutReply(thread, 'a1'))).toEqual(['a@0', 'b@0']);
    expect(shape(withoutReply(thread, 'b'))).toEqual(['a@0', 'a1@1']);
    expect(withoutReply(thread, 'gone')).toBe(thread);
  });

  it('counts a quote on the quoted comment only, where its network counts quotes', () => {
    const quoted = {
      ...commentAt('a', 0),
      post: aCommentPost({ address: 'a', counts: { likes: 0, reposts: 0, replies: 0, quotes: 2 } }),
    };
    const uncounted = commentAt('b', 0);

    const edited = withQuoteCounted([quoted, uncounted], 'a');

    expect(edited[0].post?.counts.quotes).toBe(3);
    expect(edited[1]).toBe(uncounted);
    expect(withQuoteCounted([uncounted], 'b')[0].post?.counts.quotes).toBeNull();
  });

  it('counts one reply less on the comment a removed reply answered', () => {
    const nested = [
      commentAt('a', 0, 1),
      commentAt('a1', 1, 1),
      commentAt('a11', 2),
      commentAt('b', 0),
    ];

    const edited = withoutReply(nested, 'a11');

    expect(edited.map((comment) => comment.post?.counts.replies)).toEqual([1, 0, 0]);
  });
});
