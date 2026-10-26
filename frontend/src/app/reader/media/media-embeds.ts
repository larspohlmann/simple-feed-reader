import generatedFrames from './embed-frame-allowlist.generated.json';

type EmbedKind = 'audio' | 'video';
type EmbedShape = 'landscape' | 'tall' | 'portrait';

interface EmbedPlayer {
  kind: EmbedKind;
  shape: EmbedShape;
}

interface EmbedFrame extends EmbedPlayer {
  pattern: string;
}

/**
 * Turns a recovered media link into a real player. The backend can't ship an
 * `<iframe>` — its sanitizer is shared with feed ingest, and Angular's own
 * sanitizer drops iframes from `[innerHTML]` regardless — so the body carries a
 * plain link and this pass builds the element from a re-validated URL, with
 * Angular's sanitizer left on. The link is what's cached, so dropping a provider
 * takes effect on already-cached articles. Runs beside `markInsetCards`;
 * idempotent since the anchor is gone after the first pass.
 *
 * The allow-list is generated from the backend embed providers, so a provider
 * is added in exactly one place and the two sides never drift (#1048).
 * Regenerate with `app:embed:dump-frame-allowlist`.
 */
const ALLOWED = (generatedFrames as EmbedFrame[]).map((frame) => ({
  ...frame,
  pattern: new RegExp(frame.pattern),
}));

/** Every embed frame's security attributes, set by `embedFrame`. */
export const EMBED_FRAME_ATTRIBUTES = {
  /* `allow-same-origin` beside `allow-scripts` is safe only because every allowed
     URL is cross-origin: the frame gets its own origin and cannot reach the
     reader. Never add a same-origin URL to ALLOWED. */
  sandbox: 'allow-scripts allow-same-origin allow-presentation',
  referrerpolicy: 'strict-origin-when-cross-origin',
  allowfullscreen: '',
};

export function isAllowedEmbed(url: string): boolean {
  return ALLOWED.some(({ pattern }) => pattern.test(url));
}

const SOUNDCLOUD_PLAYER_PREFIX = 'https://w.soundcloud.com/player/';
const YOUTUBE_VIDEO_FRAME = /^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}$/;

/** What an embed frame loads, and the browser features it may use beyond the sandbox. */
export interface EmbedSource {
  readonly url: string;
  readonly allow?: string;
}

/** Maps an allow-listed player URL to the source its frame loads. */
export type EmbedSourceOf = (url: string) => EmbedSource;

/** The YouTube iframe API player or SoundCloud widget, for a page driving it with chapters (#1808). Only
 *  ever applied to a URL the allow-list already accepted; the suffix is ours and constant. */
export function withScriptablePlayer(url: string, origin: string): EmbedSource {
  // Without autoplay, a cross-origin player ignores the play a chapter tap sends (Brave, #1814).
  if (url.startsWith(SOUNDCLOUD_PLAYER_PREFIX)) return { url, allow: 'autoplay' };
  if (!YOUTUBE_VIDEO_FRAME.test(url)) return { url };
  return { url: `${url}?enablejsapi=1&origin=${encodeURIComponent(origin)}`, allow: 'autoplay' };
}

export function upgradeMediaEmbeds(
  host: HTMLElement,
  sourceOf: EmbedSourceOf = (url) => ({ url }),
): void {
  for (const anchor of Array.from(host.querySelectorAll('a'))) {
    const url = anchor.getAttribute('href') ?? '';
    const frame = ALLOWED.find(({ pattern }) => pattern.test(url));
    if (!frame) continue;
    anchor.replaceWith(
      embedBox(sourceOf(url), frame, anchor.textContent?.trim() || 'Embedded media'),
    );
  }
}

function boxClasses({ kind, shape }: EmbedPlayer): string[] {
  return [
    'reader-embed',
    `reader-embed--${shape}`,
    ...(kind === 'audio' ? ['reader-embed--audio'] : []),
  ];
}

function embedBox(source: EmbedSource, player: EmbedPlayer, title: string): HTMLElement {
  const box = document.createElement('div');
  box.classList.add(...boxClasses(player));
  box.appendChild(embedFrame(source, title));

  return box;
}

/** An embed frame, still detached: insert it only once built, since iOS WebKit honours `referrerpolicy` only on a frame whose src precedes insertion (#1594). */
export function embedFrame({ url, allow }: EmbedSource, title: string): HTMLIFrameElement {
  const frame = document.createElement('iframe');
  frame.setAttribute('src', url);
  frame.setAttribute('title', title);
  frame.setAttribute('loading', 'lazy');
  for (const [name, value] of Object.entries(EMBED_FRAME_ATTRIBUTES)) {
    frame.setAttribute(name, value);
  }
  if (allow) frame.setAttribute('allow', allow);

  return frame;
}
