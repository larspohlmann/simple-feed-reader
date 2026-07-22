// src/app/reader/preview-image.ts
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

/** Plain-text snippet from HTML, whitespace-collapsed. */
export function textSnippet(html: string | null): string {
  if (!html) return '';
  const doc = new DOMParser().parseFromString(html, 'text/html');
  return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}
