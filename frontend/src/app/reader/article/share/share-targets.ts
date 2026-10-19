import config from '../../../../../public/share-targets/share-targets.json';

/** An entry of public/share-targets/share-targets.json; `icon` names a file in that folder. */
export type ShareTarget =
  | { readonly kind: 'system' | 'copy' | 'divider' }
  | {
      readonly kind: 'link';
      readonly label: string;
      readonly url: string;
      readonly icon?: string;
      readonly symbol?: string;
      readonly color?: string;
    };

export const SHARE_TARGETS = config as readonly ShareTarget[];

/** Fills `{url}`, `{title}` and `{text}` (title and url together) into a target's address. */
export function shareUrl(template: string, url: string, title: string): string {
  const text = title === '' ? url : `${title} ${url}`;
  return template
    .replaceAll('{url}', encodeURIComponent(url))
    .replaceAll('{title}', encodeURIComponent(title))
    .replaceAll('{text}', encodeURIComponent(text));
}

/** iPadOS reports a Mac user agent, so `Macintosh` covers it. */
export function shareGlyph(userAgent: string = navigator.userAgent): 'ios_share' | 'share' {
  return /iPhone|iPad|iPod|Macintosh/.test(userAgent) ? 'ios_share' : 'share';
}
