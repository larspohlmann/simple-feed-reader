// src/app/reader/slug.ts

/**
 * Builds the value for the `?entry=` deep-link param: the entry id followed by a
 * human-readable slug of the title (e.g. "514-wadephul-afrika-chancenkontinent").
 * The id drives the lookup (parsed back by entryIdFromParam); the slug is purely
 * cosmetic, so a changed or stale title never breaks the link and there is no
 * unique-slug column to maintain. It stays a query param, so no server URL
 * rewrite is needed — works on static hosting (e.g. Strato).
 */
export function entryParam(id: number, title: string): string {
  const slug = title
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '') // drop combining diacritics: u-umlaut->u, e-acute->e
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-') // any run of non-alphanumerics → one hyphen
    .replace(/^-+|-+$/g, '') // trim leading/trailing hyphens
    .slice(0, 80)
    .replace(/-+$/g, ''); // re-trim if the slice cut mid-hyphen
  return slug === '' ? String(id) : `${id}-${slug}`;
}

/**
 * Parses the entry id from an `?entry=` value that is either a bare id ("514")
 * or an id-prefixed slug ("514-some-title"). Returns null for anything without a
 * positive leading integer, so a garbage param reads as "no entry open".
 */
export function entryIdFromParam(v: string | null): number | null {
  if (v == null) return null;
  const m = /^(\d+)(?:-|$)/.exec(v);
  if (m == null) return null;
  const n = Number(m[1]);
  return Number.isInteger(n) && n > 0 ? n : null;
}
