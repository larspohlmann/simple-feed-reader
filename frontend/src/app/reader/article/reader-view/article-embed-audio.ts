import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia } from '../../audio/embed-media';
import { toEmbedTrack } from '../../audio/embed-track';
import { ListenLauncher } from '../../audio/listen-launcher';
import { pauseEmbedFrame } from '../../media/embed-frame-commands';
import { EntryDto } from '../../models';
import type { TrackDetails } from '../../audio/audio-track';
import { ListenActions } from '../player-bar/embed-listen';

/** What one rendered body's embeds become tracks of. */
export interface EmbedListenContext {
  readonly entry: EntryDto | null;
  /** What an embed's track is named, timed and chaptered by; null while nothing is known. */
  readonly detailsOf: (media: EmbedMedia) => TrackDetails | null;
  readonly onListened: (track: AudioTrack) => void;
}

/** One rendered body's Listen and queue actions, wired to the player for the open entry (#1815). */
export class ArticleEmbedAudio {
  constructor(
    private readonly audio: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  /** The Listen and queue actions of the open entry; none before the entry is known. */
  actions(context: EmbedListenContext): ListenActions | undefined {
    const entry = context.entry;
    if (!entry) return undefined;
    const trackOf = (media: EmbedMedia): AudioTrack =>
      toEmbedTrack(entry, media, context.detailsOf(media));
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
