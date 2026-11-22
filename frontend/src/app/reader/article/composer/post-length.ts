import { SocialNetworkName } from '../../../core/social-network';

/** Mastodon counts every link as this many characters, whatever its length. */
const MASTODON_LINK_LENGTH = 23;
const LINK = /https?:\/\/\S+/g;
/** Mastodon counts a mention of a remote account by its username alone. */
const REMOTE_MENTION = /(^|[^\w/])@(\w+)@[\w.-]*\w/g;

let graphemes: Intl.Segmenter | null | undefined;

/**
 * How long a post counts on its network: Bluesky counts graphemes, Lemmy code points, and Mastodon
 * graphemes of the content warning and the text together.
 */
export function postLength(text: string, network: SocialNetworkName, contentWarning = ''): number {
  switch (network) {
    case 'bluesky':
      return graphemeCount(text);
    case 'mastodon':
      return mastodonLength(contentWarning, text);
    case 'lemmy':
      return [...text].length;
  }
}

function graphemeCount(text: string): number {
  graphemes ??= typeof Intl.Segmenter === 'undefined' ? null : new Intl.Segmenter();
  return graphemes === null ? [...text].length : [...graphemes.segment(text)].length;
}

function mastodonLength(contentWarning: string, text: string): number {
  const links = text.match(LINK) ?? [];
  const withoutLinks = text.replace(LINK, '').replace(REMOTE_MENTION, '$1@$2');
  return graphemeCount(contentWarning + withoutLinks) + links.length * MASTODON_LINK_LENGTH;
}
