import { EMPTY, Subscription, catchError } from 'rxjs';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { EmbedDetailsApi, EmbedDetailsDto } from '../../embed-details-api';

interface EmbedFrames {
  readonly media: EmbedMedia;
  readonly frames: HTMLIFrameElement[];
}

type DetailsFound = (frame: HTMLIFrameElement, media: EmbedMedia, details: EmbedDetailsDto) => void;

/** The own title, length and chapters of a body's embedded videos and tracks, asked for once per embed (#1824). */
export class ArticleEmbedDetails {
  private readonly byEmbedUrl = new Map<string, EmbedDetailsDto>();
  private subscriptions: Subscription[] = [];

  constructor(private readonly api: EmbedDetailsApi) {}

  /** Asks for every frame's embed once; `found` runs per frame for every answer. */
  load(frames: HTMLIFrameElement[], found: DetailsFound): void {
    for (const { media, frames: framesOfEmbed } of framesByEmbed(frames)) {
      const subscription = this.api
        .detailsOf(media)
        .pipe(catchError(() => EMPTY))
        .subscribe((details) => {
          this.byEmbedUrl.set(media.url, details);
          for (const frame of framesOfEmbed) found(frame, media, details);
        });
      this.subscriptions.push(subscription);
    }
  }

  /** What is known of an embed URL; null before its answer arrives or when none came. */
  detailsOf(embedUrl: string): EmbedDetailsDto | null {
    return this.byEmbedUrl.get(embedUrl) ?? null;
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
