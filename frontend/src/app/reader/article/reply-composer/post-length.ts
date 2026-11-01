import { SocialNetworkName } from '../../../core/social-network';

/** Mastodon counts every link as this many characters, whatever its length. */
const MASTODON_LINK_LENGTH = 23;
const LINK = /https?:\/\/\S+/g;

let graphemes: Intl.Segmenter | null | undefined;

/** How long a post's text counts on its network: Bluesky counts graphemes, Mastodon code points. */
export function postLength(text: string, network: SocialNetworkName): number {
  return network === 'bluesky' ? graphemeCount(text) : mastodonLength(text);
}

function graphemeCount(text: string): number {
  graphemes ??= typeof Intl.Segmenter === 'undefined' ? null : new Intl.Segmenter();
  return graphemes === null ? [...text].length : [...graphemes.segment(text)].length;
}

function mastodonLength(text: string): number {
  const links = text.match(LINK) ?? [];
  const withoutLinks = text.replace(LINK, '');
  return [...withoutLinks].length + links.length * MASTODON_LINK_LENGTH;
}
