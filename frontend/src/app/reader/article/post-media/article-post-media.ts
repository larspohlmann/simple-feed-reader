import { isPost } from '../../entry/entry-text';
import { EntryMediumDto } from '../../entry-medium';
import { EntryDto } from '../../models';

/** A post's own media the article has not shown yet: not a poster, a link card's picture, or in the body. */
export function articlePostMedia(entry: EntryDto, bodyHtml: string): EntryMediumDto[] {
  if (!isPost(entry)) return [];
  const shown = new Set([
    ...entry.media.map((medium) => medium.previewImageUrl),
    ...entry.linkCards.map((card) => card.imageUrl),
    ...bodySources(bodyHtml),
  ]);
  return entry.media.filter((medium) => !shown.has(medium.url));
}

function bodySources(html: string): string[] {
  const template = document.createElement('template');
  template.innerHTML = html;
  return Array.from(
    template.content.querySelectorAll('img[src], video[src], source[src]'),
    (node) => node.getAttribute('src') ?? '',
  );
}
