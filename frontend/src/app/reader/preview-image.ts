// src/app/reader/preview-image.ts
import { EntryDto } from './models';

/** Parse HTML inertly and return the first absolute https image src, or null.
 *  http/relative/data srcs are rejected: the app is https, so http images are
 *  mixed-content-blocked, and relative srcs can't be resolved without a base. */
export function firstPreviewImage(
  contentHtml: string | null,
  summary: string | null = null,
): string | null {
  return pickImage(contentHtml) ?? pickImage(summary);
}

function pickImage(html: string | null): string | null {
  if (!html) return null;
  const doc = new DOMParser().parseFromString(html, 'text/html');
  for (const img of Array.from(doc.querySelectorAll('img'))) {
    const src = img.getAttribute('src') ?? '';
    if (src.startsWith('https://')) return src;
  }
  return null;
}

/** A body that is nothing but a serialised null. Python feed generators emit
 *  "None" for an absent field, JS ones "null"/"undefined"; Die Zeit ships the
 *  first after its image. Such a body carries no copy, so a preview treats it as
 *  empty and falls back to title-only. */
const NULL_LEAK = /^(none|null|undefined)$/i;

/** Plain-text snippet from HTML, whitespace-collapsed. */
export function textSnippet(html: string | null): string {
  if (!html) return '';
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const text = (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
  return NULL_LEAK.test(text) ? '' : text;
}

export interface EntryImage {
  url: string;
  /** Declared width, or null when the feed did not say. */
  width: number | null;
  height: number | null;
}

/** The entry's image: the persisted field when present, else an inline <img>.
 *  The fallback exists for rows ingested before the image column landed — a
 *  refresh only backfills what the feed still serves, so the deep archive keeps
 *  depending on inline markup indefinitely. */
export function entryImage(entry: EntryDto): EntryImage | null {
  if (entry.imageUrl) {
    return { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
  }
  const inline = firstPreviewImage(entry.contentHtml, entry.summary);
  return inline === null ? null : { url: inline, width: null, height: null };
}
