import {
  DestroyRef,
  ElementRef,
  Injectable,
  Injector,
  NgZone,
  Signal,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { decorateArticle } from '../decorators/decorate-article';
import { toggleCinemaByKey } from '../decorators/reader-cinema';
import { releaseHlsStreams } from '../../media/hls-streams';
import { UnplayableVideoService } from '../../media/unplayable-video.service';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { HandPlayedMedia } from '../../media/autoplay/hand-played-media';
import { withPlayerApi } from '../../media/media-embeds';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { ChapterDto } from '../../models';
import { ArticleChapters } from './article-chapters';

export interface ArticleBodyParts {
  readonly content: Signal<ElementRef<HTMLElement> | undefined>;
  readonly html: Signal<string>;
  readonly pageUrl: () => string | null;
  readonly enclosureUrl: () => string | null;
  readonly chapters: () => ChapterDto[];
  readonly audioTrack: () => AudioTrack | null;
  readonly decorated: (host: HTMLElement) => void;
}

/** The rendered article body's DOM wiring: its decorations, its media recovery, its media's
 *  turn in the article's one-at-a-time playback and the `t` cinema key. */
@Injectable()
export class ArticleBody {
  private readonly i18n = inject(TranslocoService);
  private readonly injector = inject(Injector);
  private readonly unplayableVideos = inject(UnplayableVideoService);
  private readonly zone = inject(NgZone);
  private readonly media = new HandPlayedMedia(inject(ListAutoplayCoordinator));
  private readonly chapters = new ArticleChapters(inject(AudioPlayerService), inject(Injector));
  private content: Signal<ElementRef<HTMLElement> | undefined> = signal(undefined);
  private destroyed = false;

  constructor() {
    const onKeydown = (event: KeyboardEvent): void =>
      toggleCinemaByKey(event, this.content()?.nativeElement);
    document.addEventListener('keydown', onKeydown);
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      this.chapters.release();
      document.removeEventListener('keydown', onKeydown);
    });
  }

  connect(parts: ArticleBodyParts): void {
    this.content = parts.content;
    this.recoverFailedMedia(parts);
    this.decorateEachRender(parts);
  }

  /** Body media arrive through [innerHTML], so one capturing listener gives images the proxy
   *  fallback and failed videos a link out (error events do not bubble). */
  private recoverFailedMedia(parts: ArticleBodyParts): void {
    effect(
      (onCleanup) => {
        const content = parts.content()?.nativeElement;
        if (!content) return;
        const recover = (event: Event) => {
          if (event.target instanceof HTMLImageElement) {
            void this.injector.get(ImageProxyService).recover(event.target);
            return;
          }
          this.unplayableVideos.replace(event.target, parts.pageUrl());
        };
        content.addEventListener('error', recover, true);
        onCleanup(() => content.removeEventListener('error', recover, true));
      },
      { injector: this.injector },
    );
  }

  /** Re-decorate whenever the rendered HTML changes (new article, or Reader/Original toggle),
   *  releasing the swapped-out body's streams; the effect never re-runs under a playing video. */
  private decorateEachRender(parts: ArticleBodyParts): void {
    const chapters = computed(parts.chapters, { equal: sameChapters });
    effect(
      (onCleanup) => {
        parts.html();
        const chapterList = chapters();
        // Depend on the container too, not just the HTML: when extraction fails,
        // the HTML recomputes to the same string and never notifies, so the
        // container replacing the placeholder is the only render signal (#101).
        const content = parts.content()?.nativeElement;
        if (!content) return;
        onCleanup(() => {
          releaseHlsStreams(content);
          this.media.forgetAll();
          this.chapters.release();
        });
        queueMicrotask(() => {
          const host = parts.content()?.nativeElement;
          if (!host || this.destroyed) return;
          decorateArticle(host, this.i18n, {
            enclosureUrl: parts.enclosureUrl(),
            frameUrl: chapterList.length
              ? (url) => withPlayerApi(url, window.location.origin)
              : undefined,
          });
          this.chapters.attach(
            host,
            { chapters: chapterList, heading: this.i18n.translate('reader.chapters') },
            parts.audioTrack(),
          );
          this.zone.runOutsideAngular(() => this.media.follow(host));
          parts.decorated(host);
        });
      },
      { injector: this.injector },
    );
  }
}

function sameChapters(left: ChapterDto[], right: ChapterDto[]): boolean {
  return (
    left.length === right.length &&
    left.every(
      (chapter, index) =>
        chapter.startSeconds === right[index].startSeconds && chapter.title === right[index].title,
    )
  );
}
