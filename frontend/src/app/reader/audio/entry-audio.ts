import { computed, untracked } from '@angular/core';
import { AudioPlayerService } from '../audio-player.service';
import { firstAudioAttachment, toAudioTrack } from './audio-attachment';
import { ChapterDto, EntryDto } from '../models';
import { formatDuration } from '../format';

/** An entry's audio enclosure as the player sees it, with the controls the
 *  article view and the list rows share (#1436). */
export class EntryAudio {
  readonly attachment = computed(() => firstAudioAttachment(this.entry()?.attachments ?? []));

  readonly track = computed(() => {
    const entry = this.entry();
    const attachment = this.attachment();
    return entry && attachment ? toAudioTrack(entry, attachment, this.chapters()) : null;
  });

  /** The feed-declared length as `m:ss`, or null when the feed declares none. */
  readonly duration = computed(() => {
    const seconds = this.attachment()?.durationInSeconds;
    return seconds ? formatDuration(seconds) : null;
  });

  readonly queued = computed(() => {
    const track = this.track();
    return track !== null && this.player.isQueued(track.url);
  });

  readonly playing = computed(() => {
    const track = this.track();
    return track !== null && this.player.playing() && this.player.current()?.url === track.url;
  });

  constructor(
    private readonly entry: () => EntryDto | null | undefined,
    private readonly player: AudioPlayerService,
    private readonly chapters: () => ChapterDto[] = () => [],
  ) {}

  play(): void {
    const track = this.track();
    if (track) this.player.play(track);
  }

  togglePlaying(): void {
    if (this.playing()) this.player.toggle();
    else this.play();
  }

  /** Gives the queued track its chapters once the article has them; reads nothing of the queue. */
  shareChapters(): void {
    const track = this.track();
    const chapters = track?.chapters;
    if (!track || !chapters) return;
    untracked(() =>
      this.player.describe(track.url, { title: null, durationSeconds: null, chapters }),
    );
  }

  toggleQueued(): void {
    const track = this.track();
    if (track) this.player.toggleQueued(track);
  }
}
