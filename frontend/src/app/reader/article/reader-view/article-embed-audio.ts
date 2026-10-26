import { EffectRef, Injector, effect } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia } from '../../audio/embed-media';
import { toEmbedTrack } from '../../audio/embed-track';
import { pauseEmbedFrame } from '../../media/embed-frame-commands';
import { ChapterDto, EntryDto } from '../../models';
import { EmbedListenControl, ListenActions } from '../decorators/reader-listen';

/** What one rendered body's embeds become tracks of. */
export interface EmbedListenContext {
  readonly entry: EntryDto | null;
  /** The chapters an embed's track carries: the body's, for the embed whose list the body shows. */
  readonly chaptersOf: (media: EmbedMedia) => ChapterDto[];
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
      toEmbedTrack(entry, media, context.chaptersOf(media));
    return {
      listen: (media, frame) => {
        pauseEmbedFrame(frame);
        const track = trackOf(media);
        this.audio.play(track);
        context.onListened(track);
      },
      toggleQueued: (media) => {
        if (this.audio.isQueued(media.url)) this.audio.dequeue(media.url);
        else this.audio.enqueue(trackOf(media));
      },
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
