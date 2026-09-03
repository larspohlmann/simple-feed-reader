/**
 * Builds the value for the `?entry=` deep-link param: id + a cosmetic slug
 * of the title. The id alone drives the lookup (`entryIdFromParam`), so a
 * changed title never breaks the link. A query param, not a URL rewrite, so
 * it works on static hosting (e.g. Strato).
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
  return Number.isInteger(n) && n > 0 && n <= Number.MAX_SAFE_INTEGER ? n : null;
}
