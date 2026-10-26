import {
  finiteNumberOf,
  LISTENING,
  playerCommand,
  playerInfoOf,
  playerMessageOf,
  PlayerMessage,
  YOUTUBE_PLAYER_ORIGIN,
} from '../../media/youtube-player-protocol';
import { youTubeVideoId } from '../embed-media';
import { FramePlayback } from './frame-playback';

const ENDED = 0;
const PLAYING = 1;
const PAUSED = 2;

/** Plays a YouTube video's sound through its iframe API in the hidden frame (#1815). */
export class YouTubePlayback extends FramePlayback {
  constructor(host: HTMLElement) {
    super(host, YOUTUBE_PLAYER_ORIGIN, 'YouTube');
  }

  protected frameUrlFor(url: string): string {
    const videoId = youTubeVideoId(url) ?? '';
    const pageOrigin = encodeURIComponent(window.location.origin);
    return `${YOUTUBE_PLAYER_ORIGIN}/embed/${videoId}?enablejsapi=1&origin=${pageOrigin}&playsinline=1&controls=0`;
  }

  /** A paused player may answer nothing until prompted (#1814). */
  protected override promptProvider(): void {
    this.post(LISTENING);
  }

  protected isReadyMessage(data: unknown): boolean {
    return playerMessageOf(data) !== null;
  }

  protected readMessage(data: unknown): void {
    const message = playerMessageOf(data);
    if (message === null) return;
    switch (message['event']) {
      case 'infoDelivery':
      case 'initialDelivery':
        this.readInfo(playerInfoOf(message));
        return;
      case 'onStateChange':
        this.readState(message['info']);
        return;
      case 'onError':
        this.reportFailure();
        return;
      default:
        return;
    }
  }

  protected start(seconds: number | null): void {
    if (seconds !== null && seconds > 0) this.seekTo(seconds);
    this.command('playVideo', []);
  }

  protected stop(): void {
    this.command('pauseVideo', []);
  }

  protected seekTo(seconds: number): void {
    this.command('seekTo', [seconds, true]);
  }

  protected override applyRate(): void {
    this.command('setPlaybackRate', [this.playbackRate]);
  }

  private readInfo(info: PlayerMessage): void {
    const duration = finiteNumberOf(info['duration']);
    if (duration !== null && duration > 0) this.reportDuration(duration);
    const loadedFraction = finiteNumberOf(info['videoLoadedFraction']);
    if (loadedFraction !== null) this.reportLoaded(loadedFraction);
    const currentTime = finiteNumberOf(info['currentTime']);
    if (currentTime !== null) this.reportTime(currentTime);
    this.readState(info['playerState']);
  }

  private readState(state: unknown): void {
    switch (state) {
      case PLAYING:
        this.reportPlaying();
        return;
      case PAUSED:
        this.reportPaused();
        return;
      case ENDED:
        this.reportEnded();
        return;
      default:
        return;
    }
  }

  private command(name: string, commandArguments: unknown[]): void {
    this.post(playerCommand(name, commandArguments));
  }
}
