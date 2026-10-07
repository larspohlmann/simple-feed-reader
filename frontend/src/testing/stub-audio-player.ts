import { signal } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../app/reader/audio-player.service';

/** The slice of the player a control reads, with its queue kept as plain urls:
 *  jsdom plays no media, so the real service never reaches `playing`. */
export class StubAudioPlayer implements Pick<
  AudioPlayerService,
  'current' | 'playing' | 'play' | 'toggle' | 'isQueued' | 'enqueue' | 'dequeue'
> {
  readonly queue = signal<string[]>([]);
  readonly current = signal<AudioTrack | null>(null);
  readonly playing = signal(false);
  readonly played: AudioTrack[] = [];
  toggles = 0;

  play(track: AudioTrack): void {
    this.played.push(track);
  }

  toggle(): void {
    this.toggles++;
  }

  isQueued(url: string): boolean {
    return this.queue().includes(url);
  }

  enqueue(track: AudioTrack): void {
    this.queue.update((urls) => [...urls, track.url]);
  }

  dequeue(url: string): void {
    this.queue.update((urls) => urls.filter((queued) => queued !== url));
  }

  asService(): AudioPlayerService {
    return this as unknown as AudioPlayerService;
  }
}
