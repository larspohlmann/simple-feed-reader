/** One heading in the article's table of contents. */
export interface TocEntry {
  id: string;
  text: string;
  /** Heading level (2–4) — drives the TOC indentation. */
  level: number;
}

/** A stable, DOM-id-safe slug for a heading's anchor. */
export function slugify(text: string): string {
  return (
    text
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'section'
  );
}

/** Extract the article's headings into a contents list, giving each a unique
 *  id to anchor the jump. */
export function collectToc(host: HTMLElement): TocEntry[] {
  const used = new Set<string>();
  const entries: TocEntry[] = [];
  for (const heading of Array.from(host.querySelectorAll<HTMLElement>('h2, h3, h4'))) {
    const text = (heading.textContent ?? '').trim();
    if (text === '') continue;
    let id = heading.id || slugify(text);
    for (let suffix = 2; used.has(id); suffix++) id = `${slugify(text)}-${suffix}`;
    used.add(id);
    heading.id = id;
    entries.push({ id, text, level: Number(heading.tagName[1]) });
  }
  return entries;
}
