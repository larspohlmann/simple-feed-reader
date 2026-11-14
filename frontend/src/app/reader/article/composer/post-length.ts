import { AccountNetworkName } from '../../../core/social-network';

/** Mastodon counts every link as this many characters, whatever its length. */
const MASTODON_LINK_LENGTH = 23;
const LINK = /https?:\/\/\S+/g;
/** Mastodon counts a mention of a remote account by its username alone. */
const REMOTE_MENTION = /(^|[^\w/])@(\w+)@[\w.-]*\w/g;

let graphemes: Intl.Segmenter | null | undefined;

/** How long a post's text counts on its network: Bluesky counts graphemes, Mastodon and Lemmy code points. */
export function postLength(text: string, network: AccountNetworkName): number {
  switch (network) {
    case 'bluesky':
      return graphemeCount(text);
    case 'mastodon':
      return mastodonLength(text);
    case 'lemmy':
      return [...text].length;
  }
}

function graphemeCount(text: string): number {
  graphemes ??= typeof Intl.Segmenter === 'undefined' ? null : new Intl.Segmenter();
  return graphemes === null ? [...text].length : [...graphemes.segment(text)].length;
}

function mastodonLength(text: string): number {
  const links = text.match(LINK) ?? [];
  const withoutLinks = text.replace(LINK, '').replace(REMOTE_MENTION, '$1@$2');
  return [...withoutLinks].length + links.length * MASTODON_LINK_LENGTH;
}
