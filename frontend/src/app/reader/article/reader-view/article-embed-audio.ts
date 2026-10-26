import { EffectRef, Injector, effect } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia } from '../../audio/embed-media';
import { toEmbedTrack } from '../../audio/embed-track';
import { pauseEmbedFrame } from '../../media/embed-frame-commands';
import { EntryDto } from '../../models';
import type { TrackDetails } from '../../audio/audio-track';
import { EmbedListenControl, ListenActions } from '../decorators/reader-listen';

/** What one rendered body's embeds become tracks of. */
export interface EmbedListenContext {
  readonly entry: EntryDto | null;
  /** What an embed's track is named, timed and chaptered by; null while nothing is known. */
  readonly detailsOf: (media: EmbedMedia) => TrackDetails | null;
  readonly onListened: (track: AudioTrack) => void;
}

/** One rendered body's embed controls, wired to the player for the open entry (#1815). */
export class ArticleEmbedAudio {
  private following: EffectRef | null = null;

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
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
        this.audio.play(track);
        context.onListened(track);
      },
      toggleQueued: (media) => this.audio.toggleQueued(trackOf(media)),
    };
  }

  /** Keeps every control's queue state in step with the playlist until the next body or release. */
  follow(controls: EmbedListenControl[]): void {
    this.release();
    if (controls.length === 0) return;
    this.following = effect(
      () => {
        for (const control of controls) {
          control.showQueued(this.audio.isQueued(control.media.url));
        }
      },
      { injector: this.injector },
    );
  }

  release(): void {
    this.following?.destroy();
    this.following = null;
  }
}
