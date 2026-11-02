import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia } from '../../audio/embed-media';
import { EmbedTrackOrigin, toEmbedTrack } from '../../audio/embed-track';
import { ListenLauncher } from '../../audio/listen-launcher';
import { pauseEmbedFrame } from '../../media/embed-frame-commands';
import type { TrackDetails } from '../../audio/audio-track';
import { ListenActions } from '../player-bar/embed-listen';

export interface EmbedListenContext {
  readonly origin: EmbedTrackOrigin | null;
  readonly detailsOf: (media: EmbedMedia) => TrackDetails | null;
  readonly onListened: (track: AudioTrack) => void;
}

export class ArticleEmbedAudio {
  constructor(
    private readonly audio: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  actions(context: EmbedListenContext): ListenActions | undefined {
    const origin = context.origin;
    if (!origin) return undefined;
    const trackOf = (media: EmbedMedia): AudioTrack =>
      toEmbedTrack(origin, media, context.detailsOf(media));
    return {
      listen: (media, frame) => {
        pauseEmbedFrame(frame, media.kind);
        const track = trackOf(media);
        this.launcher.listen(track, () => context.onListened(track));
      },
      toggleQueued: (media) => this.audio.toggleQueued(trackOf(media)),
    };
  }
}
