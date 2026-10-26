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
import { withScriptablePlayer } from '../../media/media-embeds';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { ChapterDto, EntryDto } from '../../models';
import { EmbedDetailsApi, EmbedDetailsDto } from '../../embed-details-api';
import { ArticleChapters, apiPlayerFrame, chapterFrames } from './article-chapters';
import { ArticleEmbedAudio } from './article-embed-audio';
import { ArticleEmbedDetails } from './article-embed-details';

const NOTHING_KNOWN: EmbedDetailsDto = { title: null, durationSeconds: null, chapters: [] };

export interface ArticleBodyParts {
  readonly content: Signal<ElementRef<HTMLElement> | undefined>;
  readonly html: Signal<string>;
  readonly pageUrl: () => string | null;
  readonly enclosureUrl: () => string | null;
  readonly chapters: () => ChapterDto[];
  readonly audioTrack: () => AudioTrack | null;
  readonly entry: () => EntryDto | null;
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
  private readonly audio = inject(AudioPlayerService);
  private readonly chapters = new ArticleChapters(this.audio, this.injector);
  private readonly embedAudio = new ArticleEmbedAudio(this.audio, this.injector);
  private readonly embedDetails = new ArticleEmbedDetails(inject(EmbedDetailsApi));
  private content: Signal<ElementRef<HTMLElement> | undefined> = signal(undefined);
  private destroyed = false;

  constructor() {
    const onKeydown = (event: KeyboardEvent): void =>
      toggleCinemaByKey(event, this.content()?.nativeElement);
    document.addEventListener('keydown', onKeydown);
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      this.chapters.release();
      this.embedAudio.release();
      this.embedDetails.release();
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
          this.embedAudio.release();
          this.embedDetails.release();
        });
        queueMicrotask(() => {
          const host = parts.content()?.nativeElement;
          if (!host || this.destroyed) return;
          this.render(parts, host, chapterList);
        });
      },
      { injector: this.injector },
    );
  }

  private render(parts: ArticleBodyParts, host: HTMLElement, chapterList: ChapterDto[]): void {
    const track = parts.audioTrack();
    const chapteredEmbed = (): EmbedMedia | null => chapteredEmbedOf(host, chapterList, track);
    const bodyChaptersOf = (url: string): ChapterDto[] | null =>
      url === chapteredEmbed()?.url ? chapterList : null;
    const controls = decorateArticle(host, this.i18n, {
      enclosureUrl: parts.enclosureUrl(),
      embedSource: (url) => withScriptablePlayer(url, window.location.origin),
      listen: this.embedAudio.actions({
        entry: parts.entry(),
        detailsOf: (media) =>
          listenDetails(this.embedDetails.detailsOf(media.url), bodyChaptersOf(media.url)),
        onListened: (listened) => this.chapters.followTrack(listened),
      }),
    });
    const heading = this.i18n.translate('reader.chapters');
    this.zone.runOutsideAngular(() => {
      this.chapters.attach(host, { chapters: chapterList, heading }, track);
      this.embedAudio.follow(controls);
      // A video queued from a list row gains its chapters once its article opens (#1810).
      const embed = chapteredEmbed();
      if (embed) this.audio.describe(embed.url, { ...NOTHING_KNOWN, chapters: chapterList });
      this.loadEmbedDetails(chapterFrames(host), heading, bodyChaptersOf);
      this.media.follow(host);
    });
    parts.decorated(host);
  }

  private loadEmbedDetails(
    frames: HTMLIFrameElement[],
    heading: string,
    bodyChaptersOf: (url: string) => ChapterDto[] | null,
  ): void {
    this.embedDetails.load(frames, (frame, media, details) => {
      const bodyChapters = bodyChaptersOf(media.url);
      if (bodyChapters === null && details.chapters.length > 0) {
        this.chapters.attachToFrame(frame, { chapters: details.chapters, heading });
      }
      this.audio.describe(media.url, listenDetails(details, bodyChapters));
    });
  }
}

/** An embed's fetched details, carrying the body's chapters instead when the body lists them for it. */
function listenDetails<Fetched extends EmbedDetailsDto | null>(
  fetched: Fetched,
  bodyChapters: ChapterDto[] | null,
): Fetched | EmbedDetailsDto {
  if (bodyChapters === null) return fetched;
  return { ...(fetched ?? NOTHING_KNOWN), chapters: bodyChapters };
}

/** The embed the body's chapters belong to: the player `ArticleChapters` drives, unless an episode owns them. */
function chapteredEmbedOf(
  host: HTMLElement,
  chapters: ChapterDto[],
  track: AudioTrack | null,
): EmbedMedia | null {
  if (chapters.length === 0 || track) return null;
  const frame = apiPlayerFrame(host);
  return frame ? embedMediaOfFrame(frame) : null;
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
