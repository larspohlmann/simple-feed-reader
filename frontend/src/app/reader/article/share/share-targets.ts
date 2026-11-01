import config from '../../../../../public/share-targets/share-targets.json';

interface ShareEntry {
  readonly label?: string;
  readonly labelKey?: string;
  /** A logo file next to the config, drawn in `color`. */
  readonly icon?: string;
  readonly symbol?: string;
  readonly color?: string;
  readonly external?: boolean;
}

/** An entry of public/share-targets/share-targets.json. */
export type ShareTarget =
  | { readonly kind: 'divider' }
  | (ShareEntry & { readonly kind: 'system' | 'copy' })
  | (ShareEntry & { readonly kind: 'link'; readonly url: string });

export const SHARE_TARGETS = (config as readonly ShareTarget[]).filter(
  (target) => target.kind !== 'system' || typeof navigator.share === 'function',
);

/** iPadOS reports a Mac user agent, so `Macintosh` covers it. */
export const SHARE_GLYPH = /iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent)
  ? 'ios_share'
  : 'share';

export function shareLogoUrl(file: string): string {
  return `share-targets/${file}`;
}

/** Fills `{url}`, `{title}` and `{text}` (title and url together) into a target's address. */
export function shareUrl(template: string, url: string, title: string): string {
  const text = title === '' ? url : `${title} ${url}`;
  return template
    .replaceAll('{url}', encodeURIComponent(url))
    .replaceAll('{title}', encodeURIComponent(title))
    .replaceAll('{text}', encodeURIComponent(text));
}
