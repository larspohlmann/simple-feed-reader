import { EmbedSourceOf, upgradeMediaEmbeds } from '../../media/media-embeds';
import { markLeadParagraph } from './lead-paragraph';
import { markInsetCards } from './reader-cards';
import { fitReaderImages } from './reader-image-fit';
import { highlightCodeBlocks } from './code-highlight';
import { armHlsStreams } from '../../media/hls-streams';
import { playVideosInline } from './inline-videos';
import { expandFaqDisclosures } from './reader-faq';
import { removeEnclosurePlayers } from './enclosure-players';
import { wrapCinemaBoxes } from './reader-cinema';

/** What one render's decorations depend on beyond the body itself. */
export interface ArticleDecorations {
  enclosureUrl: string | null;
  embedSource?: EmbedSourceOf;
}

/** Every decoration the rendered article body gets, in order. */
export function decorateArticle(host: HTMLElement, decorations: ArticleDecorations): void {
  openExternalLinksInNewTab(host);
  removeEnclosurePlayers(host, decorations.enclosureUrl);
  markLeadParagraph(host);
  markInsetCards(host);
  fitReaderImages(host);
  void highlightCodeBlocks(host);
  upgradeMediaEmbeds(host, decorations.embedSource);
  wrapCinemaBoxes(host);
  expandFaqDisclosures(host);
  playVideosInline(host);
  armHlsStreams(host);
}

export function openExternalLinksInNewTab(host: HTMLElement): void {
  for (const link of Array.from(host.querySelectorAll('a'))) {
    // Leave in-page fragment anchors alone; only external links open in a new tab.
    if ((link.getAttribute('href') ?? '').startsWith('#')) continue;
    if (link.target !== '_blank') {
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
    }
  }
}
