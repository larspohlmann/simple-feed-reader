import { computed } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { formatDuration } from '../format';
import { EmbedTrackOrigin } from './embed-track';
import { ListenLauncher } from './listen-launcher';
import { ListenOffer } from './listen-offer';

const ISO_WHOLE_SECONDS = /^PT(\d+)S$/;

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
    durationInSeconds: secondsOf(
      element.querySelector(':scope > time[datetime]')?.getAttribute('datetime'),
    ),
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
  for (const source of Array.from(element.querySelectorAll(':scope > source[src]'))) {
    const url = source.getAttribute('src')?.trim();
    if (url && isAudioType(source.getAttribute('type'))) return url;
  }
  return null;
}

function isAudioType(type: string | null): boolean {
  return !type || type.startsWith('audio/');
}

function secondsOf(iso: string | null | undefined): number | null {
  const seconds = Number(ISO_WHOLE_SECONDS.exec(iso ?? '')?.[1] ?? 0);
  return seconds > 0 ? seconds : null;
}
