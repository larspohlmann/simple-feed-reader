import { isPost } from '../../entry/entry-text';
import { EntryMediumDto, MAX_SHARED_MEDIA, withoutPosters } from '../../entry-medium';
import { EntryDto } from '../../models';

/** A post's own media the article has not shown yet, at most four. */
export function articlePostMedia(entry: EntryDto, bodyHtml: string): EntryMediumDto[] {
  if (!isPost(entry) || entry.media.length === 0) return [];
  const shown = new Set<string>([
    ...entry.linkCards.flatMap((card) => card.imageUrl ?? []),
    ...bodySources(bodyHtml),
  ]);
  return withoutPosters(entry.media)
    .filter((medium) => !shown.has(medium.url))
    .slice(0, MAX_SHARED_MEDIA);
}

function bodySources(html: string): string[] {
  const template = document.createElement('template');
  template.innerHTML = html;
  return Array.from(
    template.content.querySelectorAll('img[src], video[src], source[src]'),
    (node) => node.getAttribute('src') ?? '',
  );
}
