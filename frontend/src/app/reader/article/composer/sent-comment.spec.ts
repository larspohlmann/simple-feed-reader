import { SentPost } from './composer-dialog.component';
import { sentComment } from './sent-comment';

describe('sentComment', () => {
  const sent: SentPost = {
    address: 'at://did:plc:alice/app.bsky.feed.post/3',
    url: 'https://bsky.app/profile/did:plc:alice/post/3',
    text: '<b>Hi</b> & bye\nsee you\n\nlater ',
    authorHandle: '@alice.bsky.social',
    shown: {
      kind: 'reply',
      network: 'bluesky',
      authorName: null,
      authorHandle: null,
      excerpt: '',
      card: null,
    },
  };

  it('shows the text escaped, in paragraphs, as the reader’s own fresh reply', () => {
    const comment = sentComment(sent, new Date('2026-11-01T12:00:00Z'));

    expect(comment.html).toBe('<p>&lt;b&gt;Hi&lt;/b&gt; &amp; bye<br>see you</p><p>later</p>');
    expect(comment.authorHandle).toBe('@alice.bsky.social');
    expect(comment.url).toBe(sent.url);
    expect(comment.publishedAt).toBe('2026-11-01T12:00:00.000Z');
    expect(comment.post).toMatchObject({ address: sent.address, isOwn: true, canReply: true });
    expect(comment.post?.counts).toEqual({ likes: 0, reposts: 0, replies: 0, quotes: null });
  });
});
