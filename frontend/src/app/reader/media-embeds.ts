/**
 * Turns a recovered media link into a real player.
 *
 * The backend cannot ship an `<iframe>`: its sanitizer is shared with feed
 * ingest, so allowing one there would let any feed inject an arbitrary frame,
 * and Angular's own sanitizer drops iframes from `[innerHTML]` regardless. So
 * the body carries a plain link and this pass upgrades it, building the element
 * itself from a URL it re-validates. Angular's sanitizer stays on.
 *
 * Because the link is what gets cached and the upgrade happens at render,
 * dropping a provider takes effect on articles that are already in the cache.
 *
 * Runs in the reader's post-render pass beside markInsetCards. Idempotent: the
 * anchor is gone after the first pass, and a re-render replaces the whole body.
 */
const ALLOWED = [
  /^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}$/,
  /^https:\/\/w\.soundcloud\.com\/player\/\?url=https%3A%2F%2Fapi\.soundcloud\.com%2Ftracks%2F\d+$/,
  /^https:\/\/players\.brightcove\.net\/\d+\/[A-Za-z0-9_-]+\/index\.html\?videoId=\d+$/,
];

/* `allow-same-origin` beside `allow-scripts` is safe only because every allowed
   URL is cross-origin: the frame gets its own origin and cannot reach the
   reader. Never add a same-origin URL to ALLOWED. */
const SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

export function upgradeMediaEmbeds(host: HTMLElement): void {
  for (const anchor of Array.from(host.querySelectorAll('a'))) {
    const url = anchor.getAttribute('href') ?? '';
    if (!ALLOWED.some((pattern) => pattern.test(url))) continue;
    anchor.replaceWith(embedFrame(url, anchor.textContent?.trim() || 'Embedded media'));
  }
}

function embedFrame(url: string, title: string): HTMLElement {
  const box = document.createElement('div');
  box.className = 'reader-embed';

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
