import config from '../../../../../public/share-targets/share-targets.json';
import { ShareContent } from './share-content';

interface ShareEntry {
  /** Stored per account to hide the target, so it never changes once shipped. */
  readonly id: string;
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

/** The format the account stores hidden target ids in; `Preferences::TARGET_ID_PATTERN` on the server. */
export const TARGET_ID_PATTERN = /^[a-z0-9-]{1,32}$/;

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

/** A target the menu lists, as against a divider between them. */
export type ConfiguredTarget = Exclude<ShareTarget, { kind: 'divider' }>;

/** Whether a target can carry a quote: the system sheet and the clipboard can, an address only through `{text}`. */
export function carriesText(target: ConfiguredTarget): boolean {
  return target.kind !== 'link' || target.url.includes('{text}');
}

/** Fills `{url}`, `{title}` and `{text}` into a target's address. */
export function shareUrl(template: string, content: ShareContent): string {
  return template
    .replaceAll('{url}', encodeURIComponent(content.url))
    .replaceAll('{title}', encodeURIComponent(content.title))
    .replaceAll('{text}', encodeURIComponent(content.text));
}
