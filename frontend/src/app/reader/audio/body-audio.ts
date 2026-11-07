import { computed } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { formatDuration } from '../format';
import { EmbedTrackOrigin } from './embed-track';
import { ListenLauncher } from './listen-launcher';
import { ListenOffer } from './listen-offer';

const WHOLE_SECONDS = /^\d+$/;

/** The track a body <audio> plays, named and timed by its own `title` and `data-duration` when ingest set them. */
export function bodyAudioTrack(
  element: HTMLAudioElement,
  origin: EmbedTrackOrigin,
): AudioTrack | null {
  const url = sourceOf(element);
  if (!url) return null;
  return {
    url,
    title: element.getAttribute('title')?.trim() || origin.title,
    faviconUrl: origin.faviconUrl,
    imageUrl: origin.imageUrl,
    durationInSeconds: secondsOf(element.getAttribute('data-duration')),
    entry: origin.entry,
  };
}

export class BodyAudio implements ListenOffer {
  readonly queued = computed(() => this.player.isQueued(this.track.url));

  constructor(
    private readonly track: AudioTrack,
    private readonly player: AudioPlayerService,
    private readonly launcher: ListenLauncher,
  ) {}

  duration(): string | null {
    const seconds = this.track.durationInSeconds;
    return seconds ? formatDuration(seconds) : null;
  }

  play(): void {
    this.launcher.listen(this.track);
  }

  toggleQueued(): void {
    this.player.toggleQueued(this.track);
  }
}

function sourceOf(element: HTMLAudioElement): string | null {
  const own = element.getAttribute('src')?.trim();
  if (own) return own;
  return element.querySelector('source[src]')?.getAttribute('src')?.trim() || null;
}

function secondsOf(value: string | null): number | null {
  const trimmed = value?.trim() ?? '';
  const seconds = WHOLE_SECONDS.test(trimmed) ? Number(trimmed) : 0;
  return seconds > 0 ? seconds : null;
}
