import { computed, untracked } from '@angular/core';
import { AudioPlayerService } from '../audio-player.service';
import { firstAudioAttachment, toAudioTrack } from './audio-attachment';
import { chaptersOnly } from './audio-track';
import { ChapterDto, EntryDto } from '../models';
import { formatDuration } from '../format';
import { ListenLauncher } from './listen-launcher';

/** What an entry's audio is made of: the entry, and its chapters once they load. */
export interface EntryAudioSource {
  readonly entry: () => EntryDto | null | undefined;
  readonly chapters?: () => ChapterDto[];
}

/** An entry's audio enclosure as the player sees it, with the controls the
 *  article view and the list rows share (#1436). */
export class EntryAudio {
  readonly attachment = computed(() =>
    firstAudioAttachment(this.source.entry()?.attachments ?? []),
  );

  readonly track = computed(() => {
    const entry = this.source.entry();
    const attachment = this.attachment();
    return entry && attachment
      ? toAudioTrack(entry, attachment, this.source.chapters?.() ?? [])
      : null;
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
    private readonly source: EntryAudioSource,
    private readonly player: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  play(): void {
    const track = this.track();
    if (track) this.launcher.listen(track);
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
    untracked(() => this.player.describe(track.url, chaptersOnly(chapters)));
  }

  toggleQueued(): void {
    const track = this.track();
    if (track) this.player.toggleQueued(track);
  }
}
