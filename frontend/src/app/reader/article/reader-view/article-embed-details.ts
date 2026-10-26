import { EMPTY, Subscription, catchError } from 'rxjs';
import { AudioPlayerService } from '../../audio-player.service';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { TrackDetails, chaptersOnly } from '../../audio/audio-track';
import { EmbedDetailsApi } from '../../embed-details-api';
import { ChapterDto } from '../../models';

interface EmbedFrames {
  readonly media: EmbedMedia;
  readonly frames: HTMLIFrameElement[];
}

type DetailsFound = (frame: HTMLIFrameElement, details: TrackDetails) => void;

/** What is known of a body's embedded videos and tracks: their own title, length and chapters,
 *  asked for once per embed, under the body's own chapters for the embed they belong to (#1824).
 *  Every queued copy of an embed is described as its details become known. */
export class ArticleEmbedDetails {
  private readonly byEmbedUrl = new Map<string, TrackDetails>();
  private readonly bodyChapters = new Map<string, ChapterDto[]>();
  private subscriptions: Subscription[] = [];

  constructor(
    private readonly api: EmbedDetailsApi,
    private readonly audio: AudioPlayerService,
  ) {}

  /** The body's chapters for the embed they belong to; they outrank whatever its answer lists. */
  seed(embedUrl: string, chapters: ChapterDto[]): void {
    this.bodyChapters.set(embedUrl, chapters);
    this.know(embedUrl, chaptersOnly(chapters));
  }

  /** Asks for every frame's embed once; `found` runs per frame for every answer. */
  load(frames: HTMLIFrameElement[], found: DetailsFound): void {
    for (const { media, frames: framesOfEmbed } of framesByEmbed(frames)) {
      const subscription = this.api
        .detailsOf(media)
        .pipe(catchError(() => EMPTY))
        .subscribe((fetched) => {
          const details = this.know(media.url, this.underBodyChapters(media.url, fetched));
          for (const frame of framesOfEmbed) found(frame, details);
        });
      this.subscriptions.push(subscription);
    }
  }

  /** What is known of an embed URL; null while nothing is. */
  detailsOf(embedUrl: string): TrackDetails | null {
    return this.byEmbedUrl.get(embedUrl) ?? null;
  }

  release(): void {
    for (const subscription of this.subscriptions) subscription.unsubscribe();
    this.subscriptions = [];
    this.byEmbedUrl.clear();
    this.bodyChapters.clear();
  }

  private know(embedUrl: string, details: TrackDetails): TrackDetails {
    this.byEmbedUrl.set(embedUrl, details);
    this.audio.describe(embedUrl, details);
    return details;
  }

  private underBodyChapters(embedUrl: string, fetched: TrackDetails): TrackDetails {
    const chapters = this.bodyChapters.get(embedUrl);
    return chapters ? { ...fetched, chapters } : fetched;
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
