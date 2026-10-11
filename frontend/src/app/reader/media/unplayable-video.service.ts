import { Injectable, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { streamsThroughHls } from './hls-streams';

/** Swaps a body video the browser cannot decode for a `.link-card` to where it can be watched. */
@Injectable({ providedIn: 'root' })
export class UnplayableVideoService {
  private readonly i18n = inject(TranslocoService);

  replace(target: EventTarget | null, pageUrl: string | null): void {
    const video = failedVideo(target);
    if (!video || streamsThroughHls(video)) return;
    const href =
      pageUrl ?? video.getAttribute('src') ?? video.querySelector('source')?.getAttribute('src');
    video.replaceWith(this.linkCard(href ?? '', video.getAttribute('poster')));
  }

  private linkCard(href: string, poster: string | null): HTMLElement {
    const link = document.createElement('a');
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    if (poster) {
      const image = document.createElement('img');
      image.src = poster;
      image.alt = '';
      link.append(image);
    }
    link.append(
      textElement('strong', this.i18n.translate('reader.unplayableVideo.title')),
      textElement('span', this.i18n.translate('reader.unplayableVideo.action')),
      textElement('small', link.hostname),
    );
    const card = document.createElement('figure');
    card.className = 'link-card';
    card.append(link);
    return card;
  }
}

function failedVideo(target: EventTarget | null): HTMLVideoElement | null {
  if (target instanceof HTMLVideoElement) return target;
  if (!(target instanceof HTMLSourceElement)) return null;
  // Only the last <source> failing leaves the browser nothing to play.
  if (target.nextElementSibling instanceof HTMLSourceElement) return null;
  return target.closest('video');
}

function textElement(tag: 'strong' | 'span' | 'small', text: string): HTMLElement {
  const element = document.createElement(tag);
  element.textContent = text;
  return element;
}
