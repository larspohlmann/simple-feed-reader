import { signal } from '@angular/core';
import { AudioPlayerService, AudioTrack, TrackDetails } from '../app/reader/audio-player.service';

/** The slice of the player a control reads, with its queue kept as plain urls:
 *  jsdom plays no media, so the real service never reaches `playing`. */
export class StubAudioPlayer implements Pick<
  AudioPlayerService,
  | 'current'
  | 'playing'
  | 'play'
  | 'playFrom'
  | 'toggle'
  | 'isQueued'
  | 'enqueue'
  | 'dequeue'
  | 'toggleQueued'
  | 'describe'
> {
  readonly queue = signal<string[]>([]);
  readonly current = signal<AudioTrack | null>(null);
  readonly playing = signal(false);
  readonly played: AudioTrack[] = [];
  readonly playedFrom: { track: AudioTrack; seconds: number }[] = [];
  readonly described: { url: string; details: TrackDetails }[] = [];
  toggles = 0;

  play(track: AudioTrack): void {
    this.played.push(track);
  }

  playFrom(track: AudioTrack, seconds: number): void {
    this.playedFrom.push({ track, seconds });
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

  describe(url: string, details: TrackDetails): void {
    this.described.push({ url, details });
  }

  dequeue(url: string): void {
    this.queue.update((urls) => urls.filter((queued) => queued !== url));
  }

  toggleQueued(track: AudioTrack): void {
    if (this.isQueued(track.url)) this.dequeue(track.url);
    else this.enqueue(track);
  }

  asService(): AudioPlayerService {
    return this as unknown as AudioPlayerService;
  }
}
