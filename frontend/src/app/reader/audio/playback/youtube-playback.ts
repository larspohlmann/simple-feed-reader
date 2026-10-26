import { youTubeVideoId } from '../embed-media';
import { FramePlayback } from './frame-playback';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';
const FRAME_TITLE = 'YouTube';
const WIDGET = { id: 1, channel: 'widget' };
const LISTENING = { event: 'listening', ...WIDGET };
const ENDED = 0;
const PLAYING = 1;
const PAUSED = 2;

interface PlayerMessage {
  readonly event?: unknown;
  readonly info?: unknown;
}

interface PlayerInfo {
  readonly currentTime?: unknown;
  readonly duration?: unknown;
  readonly videoLoadedFraction?: unknown;
  readonly playerState?: unknown;
}

/** Plays a YouTube video's sound through its iframe API in the hidden frame (#1815). */
export class YouTubePlayback extends FramePlayback {
  private heardFromPlayer = false;

  constructor(host: HTMLElement) {
    super(host, PLAYER_ORIGIN, FRAME_TITLE);
  }

  override get src(): string {
    return super.src;
  }

  override set src(url: string) {
    this.heardFromPlayer = false;
    super.src = url;
  }

  /** A paused player may answer nothing until prompted, so a play before its first word prompts it again (#1814). */
  override play(): Promise<void> {
    const playing = super.play();
    if (!this.heardFromPlayer) this.post(LISTENING);
    return playing;
  }

  protected frameUrlFor(url: string): string {
    const videoId = youTubeVideoId(url) ?? '';
    const pageOrigin = encodeURIComponent(window.location.origin);
    return `${PLAYER_ORIGIN}/embed/${videoId}?enablejsapi=1&origin=${pageOrigin}&playsinline=1&controls=0`;
  }

  protected onFrameLoaded(): void {
    this.post(LISTENING);
  }

  protected isReadyMessage(data: unknown): boolean {
    return messageOf(data) !== null;
  }

  protected readMessage(data: unknown): void {
    const message = messageOf(data);
    if (message === null) return;
    this.heardFromPlayer = true;
    switch (message.event) {
      case 'infoDelivery':
      case 'initialDelivery':
        this.readInfo(infoOf(message.info));
        return;
      case 'onStateChange':
        this.readState(message.info);
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

  protected applyRate(rate: number): void {
    this.command('setPlaybackRate', [rate]);
  }

  private readInfo(info: PlayerInfo): void {
    const duration = finiteNumberOf(info.duration);
    if (duration !== null && duration > 0) this.reportDuration(duration);
    const loadedFraction = finiteNumberOf(info.videoLoadedFraction);
    if (loadedFraction !== null) this.reportLoaded(loadedFraction);
    const currentTime = finiteNumberOf(info.currentTime);
    if (currentTime !== null) this.reportTime(currentTime);
    this.readState(info.playerState);
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
    this.post({ event: 'command', func: name, args: commandArguments, ...WIDGET });
  }
}

function messageOf(data: unknown): PlayerMessage | null {
  if (typeof data !== 'string') return null;
  try {
    return recordOf(JSON.parse(data));
  } catch {
    return null;
  }
}

function infoOf(value: unknown): PlayerInfo {
  return recordOf(value) ?? {};
}

function recordOf(value: unknown): Record<string, unknown> | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null;
  return value as Record<string, unknown>;
}

function finiteNumberOf(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}
