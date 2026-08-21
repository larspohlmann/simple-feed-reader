// src/app/reader/feed-hero-image.ts
import { EntryDto } from './models';

/** The feed's own picture, ready to lead an article that has none of its own. */
export interface FeedHeroImage {
  url: string;
  /** As declared by the feed. Null means unknown, so no space is reserved. */
  width: number | null;
  height: number | null;
}

/** Standard layout whitespace, excluding U+00A0 which is visible text. */
const LAYOUT_WHITESPACE = /^[ \t\n\r\f\v\0]*$/;

/**
 * A CDN-agnostic identity for an image: the path basename without its extension
 * or query string, lowercased. Size-variant URLs of one photo (`/hero.jpg` vs
 * `/hero.webp?width=960`) share it; a different photo does not. Mirrors the
 * backend's `LeadImageSelector` so both ends make the same duplicate call.
 */
function imageIdentity(url: string): string {
  let path = url;
  try {
    path = new URL(url, 'https://feed-hero.invalid').pathname;
  } catch {
    // A malformed URL keeps its raw form; it only ever matches itself.
  }
  const basename = path.split('/').pop() ?? '';
  const stem = basename.replace(/\.[^.]+$/, '');
  return (stem !== '' ? stem : url).toLowerCase();
}

/** The parsed body, or null when the html cannot be parsed. */
function parseBody(bodyHtml: string): HTMLElement | null {
  try {
    return new DOMParser().parseFromString(bodyHtml, 'text/html').body;
  } catch {
    return null;
  }
}

/** Every node under a root, depth-first, in document order. */
function* nodesInRenderOrder(root: Node): Generator<Node> {
  for (const child of Array.from(root.childNodes)) {
    yield child;
    yield* nodesInRenderOrder(child);
  }
}

function isImage(node: Node): node is HTMLImageElement {
  return node.nodeType === Node.ELEMENT_NODE && (node as Element).localName === 'img';
}

function isVisibleText(node: Node): boolean {
  return node.nodeType === Node.TEXT_NODE && !LAYOUT_WHITESPACE.test(node.textContent ?? '');
}

/** Whether the first rendered node in the body is an image. */
function bodyLeadsWithImage(body: HTMLElement): boolean {
  for (const node of nodesInRenderOrder(body)) {
    if (isImage(node)) return true;
    if (isVisibleText(node)) return false;
  }
  return false;
}

/** Whether the body shows the same picture as the feed hero somewhere. */
function bodyRepeatsImage(body: HTMLElement, heroUrl: string): boolean {
  const heroIdentity = imageIdentity(heroUrl);
  for (const node of nodesInRenderOrder(body)) {
    const src = isImage(node) ? node.getAttribute('src') : null;
    if (src && imageIdentity(src) === heroIdentity) return true;
  }
  return false;
}

/**
 * The feed's picture to show above an article, or null to show none.
 *
 * A hero exists only to give a lead picture to an article whose body does not
 * already open with one. So it is suppressed when the body that will render
 * already *leads* with an image — the first rendered thing is a picture, of
 * whatever photo — because a hero on top would then stack two images at the
 * head. It is also suppressed when the body repeats the hero photo further down
 * (matched by CDN image identity), so the same picture never shows twice. This
 * mirrors the backend `LeadImageSelector`, and runs only when the backend hero
 * is null — Original view, failed extraction, or a suppressed duplicate.
 *
 * Only the persisted `imageUrl` qualifies. The inline-image fallback the list
 * surfaces use is fine for a thumbnail but not for a full-width hero, where a
 * tracking pixel or a byline avatar would be conspicuous.
 */
export function feedHeroImage(entry: EntryDto | null, bodyHtml: string): FeedHeroImage | null {
  if (!entry?.imageUrl) return null;
  const hero = { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
  const body = parseBody(bodyHtml);
  if (body && (bodyLeadsWithImage(body) || bodyRepeatsImage(body, entry.imageUrl))) return null;
  return hero;
}
