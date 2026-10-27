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
import { ChapterList } from '../decorators/reader-chapters';
import { releaseHlsStreams } from '../../media/hls-streams';
import { UnplayableVideoService } from '../../media/unplayable-video.service';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { HandPlayedMedia } from '../../media/autoplay/hand-played-media';
import { withScriptablePlayer } from '../../media/media-embeds';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { ChapterDto, EntryDto, TranscriptDto } from '../../models';
import { EmbedDetailsApi } from '../../embed-details-api';
import { ArticleChapters, apiPlayerFrame, chapterFrames } from './article-chapters';
import { ArticleEmbedAudio } from './article-embed-audio';
import { ArticleEmbedDetails } from './article-embed-details';
import { ArticleTranscript } from './article-transcript';

interface ChapteredEmbed {
  readonly frame: HTMLIFrameElement;
  readonly media: EmbedMedia;
}

interface EpisodeText {
  readonly chapters: ChapterDto[];
  readonly transcript: TranscriptDto | null;
}

export interface ArticleBodyParts {
  readonly content: Signal<ElementRef<HTMLElement> | undefined>;
  readonly html: Signal<string>;
  readonly pageUrl: () => string | null;
  readonly enclosureUrl: () => string | null;
  readonly chapters: () => ChapterDto[];
  readonly transcript: () => TranscriptDto | null;
  readonly transcriptIsTheBody: () => boolean;
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
  private readonly transcriptSection = new ArticleTranscript(this.audio, this.injector);
  private readonly embedAudio = new ArticleEmbedAudio(this.audio, this.injector);
  private readonly embedDetails = new ArticleEmbedDetails(inject(EmbedDetailsApi), this.audio);
  private content: Signal<ElementRef<HTMLElement> | undefined> = signal(undefined);
  private destroyed = false;

  constructor() {
    const onKeydown = (event: KeyboardEvent): void =>
      toggleCinemaByKey(event, this.content()?.nativeElement);
    document.addEventListener('keydown', onKeydown);
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      this.chapters.release();
      this.transcriptSection.release();
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
    const transcript = computed(parts.transcript);
    effect(
      (onCleanup) => {
        parts.html();
        const chapterList = chapters();
        const episodeTranscript = transcript();
        // Depend on the container too, not just the HTML: when extraction fails,
        // the HTML recomputes to the same string and never notifies, so the
        // container replacing the placeholder is the only render signal (#101).
        const content = parts.content()?.nativeElement;
        if (!content) return;
        onCleanup(() => {
          releaseHlsStreams(content);
          this.media.forgetAll();
          this.chapters.release();
          this.transcriptSection.release();
          this.embedAudio.release();
          this.embedDetails.release();
        });
        queueMicrotask(() => {
          const host = parts.content()?.nativeElement;
          if (!host || this.destroyed) return;
          this.render(parts, host, { chapters: chapterList, transcript: episodeTranscript });
        });
      },
      { injector: this.injector },
    );
  }

  private render(parts: ArticleBodyParts, host: HTMLElement, episode: EpisodeText): void {
    const { chapters: chapterList, transcript } = episode;
    const track = parts.audioTrack();
    const controls = decorateArticle(host, this.i18n, {
      enclosureUrl: parts.enclosureUrl(),
      embedSource: (url) => withScriptablePlayer(url, window.location.origin),
      listen: this.embedAudio.actions({
        entry: parts.entry(),
        detailsOf: (media) => this.embedDetails.detailsOf(media.url),
        onListened: (listened) => this.chapters.followTrack(listened),
      }),
    });
    const list = { chapters: chapterList, heading: this.i18n.translate('reader.chapters') };
    this.zone.runOutsideAngular(() => {
      this.attachBodyChapters(host, list, track);
      if (transcript) {
        this.transcriptSection.attach(
          host,
          {
            transcript,
            heading: this.i18n.translate('reader.transcript'),
            playFromLabel: (time) => this.i18n.translate('reader.transcriptPlayFrom', { time }),
            open: parts.transcriptIsTheBody(),
          },
          track,
        );
      }
      this.embedAudio.follow(controls);
      this.loadEmbedDetails(chapterFrames(host), list.heading);
      this.media.follow(host);
    });
    parts.decorated(host);
  }

  /** Before any fetched list, so the body's chapters are the one list under their frame. */
  private attachBodyChapters(host: HTMLElement, list: ChapterList, track: AudioTrack | null): void {
    if (track) {
      this.chapters.attachToEpisode(host, list, track);
      return;
    }
    // Only decoration gives the player frame its API flag, so the embed is resolved after it.
    const chaptered = chapteredEmbedOf(host, list.chapters);
    if (!chaptered) return;
    // A video queued from a list row gains its chapters once its article opens (#1810).
    this.embedDetails.seed(chaptered.media.url, list.chapters);
    this.chapters.attachToFrame(chaptered.frame, list);
  }

  private loadEmbedDetails(frames: HTMLIFrameElement[], heading: string): void {
    this.embedDetails.load(frames, (frame, details) =>
      this.chapters.attachToFrame(frame, { chapters: details.chapters, heading }),
    );
  }
}

/** The embed the body's chapters belong to when no episode owns them: the first video player. */
function chapteredEmbedOf(host: HTMLElement, chapters: ChapterDto[]): ChapteredEmbed | null {
  const frame = chapters.length > 0 ? apiPlayerFrame(host) : null;
  const media = frame ? embedMediaOfFrame(frame) : null;
  return frame && media ? { frame, media } : null;
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
