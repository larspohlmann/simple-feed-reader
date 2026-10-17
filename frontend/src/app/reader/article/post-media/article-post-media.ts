import { isPost } from '../../entry/entry-text';
import { EntryMediumDto } from '../../entry-medium';
import { MAX_SHARED_MEDIA } from '../../list/magazine/magazine-slot-fit';
import { EntryDto } from '../../models';

/** A post's own media the article has not shown yet, at most four. */
export function articlePostMedia(entry: EntryDto, bodyHtml: string): EntryMediumDto[] {
  if (!isPost(entry)) return [];
  const shown = new Set<string>([
    ...entry.media.flatMap((medium) => medium.previewImageUrl ?? []),
    ...entry.linkCards.flatMap((card) => card.imageUrl ?? []),
    ...bodySources(bodyHtml),
  ]);
  return entry.media.filter((medium) => !shown.has(medium.url)).slice(0, MAX_SHARED_MEDIA);
}

function bodySources(html: string): string[] {
  const template = document.createElement('template');
  template.innerHTML = html;
  return Array.from(
    template.content.querySelectorAll('img[src], video[src], source[src]'),
    (node) => node.getAttribute('src') ?? '',
  );
}
