import { EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { SentPost } from './composed-post';

/** The reader's reply as the panel shows it until the network's thread includes it. */
export function sentComment(sent: SentPost, publishedAt: Date): EntryCommentDto {
  return {
    author: null,
    authorHandle: sent.authorHandle,
    authorUrl: null,
    authorAvatarUrl: null,
    url: sent.url,
    publishedAt: publishedAt.toISOString(),
    html: paragraphsOf(sent.text),
    byEntryAuthor: false,
    depth: 0,
    score: sent.network === 'lemmy' ? 1 : null,
    media: [],
    linkCard: null,
    quote: null,
    post: sent.network === 'lemmy' ? null : sentPost(sent),
    vote: sent.network === 'lemmy' ? { address: sent.address, myVote: 1, canReply: true } : null,
  };
}

function sentPost(sent: SentPost): EntryCommentPostDto {
  return {
    address: sent.address,
    counts: { likes: 0, reposts: 0, replies: 0, quotes: null },
    isLiked: false,
    isReposted: false,
    canRepost: true,
    canReply: true,
    canQuote: true,
    isOwn: true,
  };
}

function paragraphsOf(text: string): string {
  return text
    .trim()
    .split(/\n{2,}/)
    .map((paragraph) => `<p>${escaped(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

function escaped(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
