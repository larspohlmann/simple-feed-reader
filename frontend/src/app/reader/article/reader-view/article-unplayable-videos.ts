import { inputBinding } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { LinkCardDto } from '../../link-card/link-card';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { streamsThroughHls } from '../../media/hls-streams';
import { ArticleIslands } from './article-islands';

const UNPLAYABLE = 'reader-video--unplayable';

export class ArticleUnplayableVideos {
  constructor(
    private readonly islands: ArticleIslands,
    private readonly i18n: TranslocoService,
  ) {}

  /** A chapters-only re-render keeps the body, so the videos found unplayable keep their card. */
  mount(host: HTMLElement, pageUrl: string | null): void {
    for (const video of Array.from(
      host.querySelectorAll<HTMLVideoElement>(`video.${UNPLAYABLE}`),
    )) {
      this.mountCard(video, pageUrl);
    }
  }

  fail(target: EventTarget | null, pageUrl: string | null): void {
    const video = failedVideo(target);
    if (!video || streamsThroughHls(video) || video.classList.contains(UNPLAYABLE)) return;
    video.classList.add(UNPLAYABLE);
    video.pause();
    this.mountCard(video, pageUrl);
  }

  private mountCard(video: HTMLVideoElement, pageUrl: string | null): void {
    const card = this.cardOf(video, pageUrl);
    this.islands.mountBefore(video, LinkCardComponent, [
      inputBinding('card', () => card),
      inputBinding('layout', () => 'article'),
    ]);
  }

  private cardOf(video: HTMLVideoElement, pageUrl: string | null): LinkCardDto {
    const url =
      pageUrl ??
      video.getAttribute('src') ??
      video.querySelector('source')?.getAttribute('src') ??
      '';
    return {
      url,
      title: this.i18n.translate('reader.unplayableVideo.title'),
      description: this.i18n.translate('reader.unplayableVideo.action'),
      imageUrl: video.getAttribute('poster'),
      siteName: hostnameOf(url),
      readerToken: '',
    };
  }
}

function failedVideo(target: EventTarget | null): HTMLVideoElement | null {
  if (target instanceof HTMLVideoElement) return target;
  if (!(target instanceof HTMLSourceElement)) return null;
  // Only the last <source> failing leaves the browser nothing to play.
  if (target.nextElementSibling instanceof HTMLSourceElement) return null;
  return target.closest('video');
}

function hostnameOf(url: string): string {
  try {
    return new URL(url, document.baseURI).hostname;
  } catch {
    return '';
  }
}
