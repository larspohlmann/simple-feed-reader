/**
 * Turns a recovered media link into a real player. The backend can't ship an
 * `<iframe>` — its sanitizer is shared with feed ingest, and Angular's own
 * sanitizer drops iframes from `[innerHTML]` regardless — so the body carries a
 * plain link and this pass builds the element from a re-validated URL, with
 * Angular's sanitizer left on. The link is what's cached, so dropping a provider
 * takes effect on already-cached articles. Runs beside `markInsetCards`;
 * idempotent since the anchor is gone after the first pass.
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
