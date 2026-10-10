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
 * The allow-list is generated from the backend embed providers (one entry per
 * provider frame: its pattern, whether it plays audio or video, and the box
 * shape it needs), so a provider is added in exactly one place and the two
 * sides never drift (#1048, #1481).
 * Regenerate with `app:embed:dump-frame-allowlist`.
 */
const ALLOWED = (generatedFrames as EmbedFrame[]).map((frame) => ({
  ...frame,
  pattern: new RegExp(frame.pattern),
}));

/* `allow-same-origin` beside `allow-scripts` is safe only because every allowed
   URL is cross-origin: the frame gets its own origin and cannot reach the
   reader. Never add a same-origin URL to ALLOWED. */
const SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

export function upgradeMediaEmbeds(host: HTMLElement): void {
  for (const anchor of Array.from(host.querySelectorAll('a'))) {
    const url = anchor.getAttribute('href') ?? '';
    const frame = ALLOWED.find(({ pattern }) => pattern.test(url));
    if (!frame) continue;
    anchor.replaceWith(embedFrame(url, frame, anchor.textContent?.trim() || 'Embedded media'));
  }
}

const SHAPE_CLASS: Record<EmbedShape, string> = {
  landscape: '',
  tall: ' reader-embed--tall',
  portrait: ' reader-embed--portrait',
};

function boxClass({ kind, shape }: EmbedPlayer): string {
  const base = kind === 'audio' ? 'reader-embed reader-embed--audio' : 'reader-embed';
  return base + SHAPE_CLASS[shape];
}

function embedFrame(url: string, player: EmbedPlayer, title: string): HTMLElement {
  const box = document.createElement('div');
  box.className = boxClass(player);

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
