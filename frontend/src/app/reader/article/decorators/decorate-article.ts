import { TranslocoService } from '@jsverse/transloco';
import { upgradeMediaEmbeds } from '../../media-embeds';
import { markLeadParagraph } from './lead-paragraph';
import { markInsetCards } from './reader-cards';
import { fitReaderImages } from './reader-image-fit';
import { highlightCodeBlocks } from './code-highlight';
import { attachHlsStreams } from './hls-streams';
import { markNarrationPlayers } from './reader-narration';
import { expandFaqDisclosures } from './reader-faq';
import { hydrateSlideshows } from './reader-slideshow';

/** Every decoration the rendered article body gets, in order. */
export function decorateArticle(host: HTMLElement, i18n: TranslocoService): void {
  openExternalLinksInNewTab(host);
  markLeadParagraph(host);
  markInsetCards(host);
  fitReaderImages(host);
  void highlightCodeBlocks(host);
  upgradeMediaEmbeds(host);
  markNarrationPlayers(host, i18n.translate('reader.narrationPlayer'));
  expandFaqDisclosures(host);
  hydrateSlideshows(host, {
    previous: i18n.translate('reader.slideshowPrevious'),
    next: i18n.translate('reader.slideshowNext'),
    position: (current, total) => i18n.translate('reader.slideshowPosition', { current, total }),
  });
  attachHlsStreams(host);
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
