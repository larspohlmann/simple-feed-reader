import generatedFrames from './embed-frame-allowlist.generated.json';

type EmbedKind = 'audio' | 'video';

interface EmbedFrame {
  pattern: string;
  kind: EmbedKind;
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
 * The allow-list is generated from the backend embed providers (one entry per
 * provider: its `framePattern()` and whether it plays audio or video), so a
 * provider is added in exactly one place and the two sides never drift (#1048).
 * Regenerate with `app:embed:dump-frame-allowlist`.
 */
const ALLOWED = (generatedFrames as EmbedFrame[]).map(({ pattern, kind }) => ({
  pattern: new RegExp(pattern),
  kind,
}));

/* `allow-same-origin` beside `allow-scripts` is safe only because every allowed
   URL is cross-origin: the frame gets its own origin and cannot reach the
   reader. Never add a same-origin URL to ALLOWED. */
const SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

/* A Spotify collection (playlist/album/artist/show) renders a scrollable track
   list, so its player needs a tall fixed box instead of the 16:9 frame a video
   gets. A single track or episode keeps the default frame. */
const SPOTIFY_COLLECTION = /^https:\/\/open\.spotify\.com\/embed\/(?:playlist|album|artist|show)\//;

const SHORTS_FRAGMENT = '#shorts';

export function upgradeMediaEmbeds(host: HTMLElement): void {
  for (const anchor of Array.from(host.querySelectorAll('a'))) {
    const url = anchor.getAttribute('href') ?? '';
    const frame = ALLOWED.find(({ pattern }) => pattern.test(url));
    if (!frame) continue;
    anchor.replaceWith(embedFrame(url, frame.kind, anchor.textContent?.trim() || 'Embedded media'));
  }
}

function boxClass(url: string, kind: EmbedKind): string {
  const base = kind === 'audio' ? 'reader-embed reader-embed--audio' : 'reader-embed';
  if (SPOTIFY_COLLECTION.test(url)) return `${base} reader-embed--tall`;
  if (url.endsWith(SHORTS_FRAGMENT)) return `${base} reader-embed--portrait`;
  return base;
}

function embedFrame(url: string, kind: EmbedKind, title: string): HTMLElement {
  const box = document.createElement('div');
  box.className = boxClass(url, kind);

  const frame = document.createElement('iframe');
  frame.setAttribute('src', url);
  frame.setAttribute('title', title);
  frame.setAttribute('loading', 'lazy');
  frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
  frame.setAttribute('sandbox', SANDBOX);
  frame.setAttribute('allowfullscreen', '');
  box.appendChild(frame);

  return box;
}
