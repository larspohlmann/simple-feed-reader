import { computed } from '@angular/core';
import { AudioPlayerService } from '../audio-player.service';
import { firstAudioAttachment, toAudioTrack } from './audio-attachment';
import { EntryDto } from '../models';
import { formatDuration } from '../format';

/** An entry's audio enclosure as the player sees it, with the controls the
 *  article view and the list rows share (#1436). */
export class EntryAudio {
  readonly attachment = computed(() => firstAudioAttachment(this.entry()?.attachments ?? []));

  readonly track = computed(() => {
    const entry = this.entry();
    const attachment = this.attachment();
    return entry && attachment ? toAudioTrack(entry, attachment) : null;
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
  ) {}

  play(): void {
    const track = this.track();
    if (track) this.player.play(track);
  }

  togglePlaying(): void {
    if (this.playing()) this.player.toggle();
    else this.play();
  }

  toggleQueued(): void {
    const track = this.track();
    if (!track) return;
    if (this.queued()) this.player.dequeue(track.url);
    else this.player.enqueue(track);
  }
}
