import { EMPTY, Subscription, catchError } from 'rxjs';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { ChapterDto } from '../../models';
import { EmbedChaptersApi } from '../../embed-chapters-api';

interface EmbedFrames {
  readonly media: EmbedMedia;
  readonly frames: HTMLIFrameElement[];
}

type ChaptersFound = (frame: HTMLIFrameElement, media: EmbedMedia, chapters: ChapterDto[]) => void;

/** The chapters of a body's embedded videos and tracks, asked for once per embed (#1824). */
export class ArticleEmbedChapters {
  private readonly byEmbedUrl = new Map<string, ChapterDto[]>();
  private subscriptions: Subscription[] = [];

  constructor(private readonly api: EmbedChaptersApi) {}

  /** Asks for every frame's embed once; `found` runs per frame whose embed has chapters. */
  load(frames: HTMLIFrameElement[], found: ChaptersFound): void {
    for (const { media, frames: framesOfEmbed } of framesByEmbed(frames)) {
      const subscription = this.api
        .chaptersOf(media)
        .pipe(catchError(() => EMPTY))
        .subscribe(({ chapters }) => {
          if (chapters.length === 0) return;
          this.byEmbedUrl.set(media.url, chapters);
          for (const frame of framesOfEmbed) found(frame, media, chapters);
        });
      this.subscriptions.push(subscription);
    }
  }

  /** The chapters known for an embed URL; [] before they arrive or when it has none. */
  chaptersOf(embedUrl: string): ChapterDto[] {
    return this.byEmbedUrl.get(embedUrl) ?? [];
  }

  release(): void {
    for (const subscription of this.subscriptions) subscription.unsubscribe();
    this.subscriptions = [];
    this.byEmbedUrl.clear();
  }
}

function framesByEmbed(frames: HTMLIFrameElement[]): EmbedFrames[] {
  const byUrl = new Map<string, EmbedFrames>();
  for (const frame of frames) {
    const media = embedMediaOfFrame(frame);
    if (!media) continue;
    const known = byUrl.get(media.url);
    if (known) known.frames.push(frame);
    else byUrl.set(media.url, { media, frames: [frame] });
  }
  return Array.from(byUrl.values());
}
