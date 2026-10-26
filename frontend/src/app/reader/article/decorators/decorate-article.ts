import { TranslocoService } from '@jsverse/transloco';
import { EmbedSourceOf, upgradeMediaEmbeds } from '../../media/media-embeds';
import { markLeadParagraph } from './lead-paragraph';
import { markInsetCards } from './reader-cards';
import { fitReaderImages } from './reader-image-fit';
import { highlightCodeBlocks } from './code-highlight';
import { armHlsStreams } from '../../media/hls-streams';
import { playVideosInline } from './inline-videos';
import { markNarrationPlayers } from './reader-narration';
import { expandFaqDisclosures } from './reader-faq';
import { hydrateSlideshows } from './reader-slideshow';
import { removeEnclosurePlayers } from './enclosure-players';
import { addCinemaToggles } from './reader-cinema';
import {
  addEmbedListenControls,
  type EmbedListenControl,
  type ListenActions,
} from './reader-listen';

/** What one render's decorations depend on beyond the body itself. */
export interface ArticleDecorations {
  enclosureUrl: string | null;
  embedSource?: EmbedSourceOf;
  listen?: ListenActions;
}

/** Every decoration the rendered article body gets, in order; answers the listen controls it placed. */
export function decorateArticle(
  host: HTMLElement,
  i18n: TranslocoService,
  decorations: ArticleDecorations,
): EmbedListenControl[] {
  openExternalLinksInNewTab(host);
  removeEnclosurePlayers(host, decorations.enclosureUrl);
  markLeadParagraph(host);
  markInsetCards(host);
  fitReaderImages(host);
  void highlightCodeBlocks(host);
  upgradeMediaEmbeds(host, decorations.embedSource);
  addCinemaToggles(host, {
    enter: i18n.translate('reader.cinema'),
    exit: i18n.translate('reader.cinemaExit'),
  });
  const listenControls = addListenControls(host, i18n, decorations.listen);
  markNarrationPlayers(host, i18n.translate('reader.narrationPlayer'));
  expandFaqDisclosures(host);
  hydrateSlideshows(host, {
    previous: i18n.translate('reader.slideshowPrevious'),
    next: i18n.translate('reader.slideshowNext'),
    position: (current, total) => i18n.translate('reader.slideshowPosition', { current, total }),
  });
  playVideosInline(host);
  armHlsStreams(host);
  return listenControls;
}

function addListenControls(
  host: HTMLElement,
  i18n: TranslocoService,
  actions: ListenActions | undefined,
): EmbedListenControl[] {
  if (!actions) return [];
  return addEmbedListenControls(
    host,
    {
      listen: i18n.translate('reader.audioPlayer.listen'),
      listenEmbed: i18n.translate('reader.audioPlayer.listenEmbed'),
      addToPlaylist: i18n.translate('reader.audioPlayer.addToPlaylist'),
      inPlaylist: i18n.translate('reader.audioPlayer.inPlaylist'),
    },
    actions,
  );
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
