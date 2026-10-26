import { ChapterPlayer, PlaybackPosition } from './chapter-player';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';
const WIDGET = { id: 1, channel: 'widget' };

interface PlayerInfo {
  readonly currentTime?: unknown;
  readonly duration?: unknown;
}

/** Drives a YouTube embed through its iframe API; the frame must be loaded with `enablejsapi=1` (#1808). */
export class YouTubeChapterPlayer implements ChapterPlayer {
  private onPosition: ((position: PlaybackPosition | null) => void) | null = null;
  private durationSeconds: number | null = null;
  private ready = false;
  private heldStart: number | null = null;

  private readonly onMessage = (event: MessageEvent): void => {
    if (event.origin !== PLAYER_ORIGIN || event.source !== this.frame.contentWindow) return;
    this.becomeReady();
    const info = infoOf(event.data);
    const duration = secondsOf(info?.duration);
    if (duration !== null && duration > 0) this.durationSeconds = duration;
    const seconds = secondsOf(info?.currentTime);
    if (seconds !== null) this.onPosition?.({ seconds, durationSeconds: this.durationSeconds });
  };

  private readonly onLoad = (): void => this.listen();

  constructor(private readonly frame: HTMLIFrameElement) {
    window.addEventListener('message', this.onMessage);
    frame.addEventListener('load', this.onLoad);
  }

  playFrom(seconds: number): void {
    if (!this.ready) {
      this.heldStart = seconds;
      this.listen();
      return;
    }
    this.command('seekTo', [seconds, true]);
    this.command('playVideo', []);
  }

  watch(onPosition: (position: PlaybackPosition | null) => void): void {
    this.onPosition = onPosition;
  }

  release(): void {
    this.onPosition = null;
    this.heldStart = null;
    window.removeEventListener('message', this.onMessage);
    this.frame.removeEventListener('load', this.onLoad);
  }

  private becomeReady(): void {
    if (this.ready) return;
    this.ready = true;
    const start = this.heldStart;
    this.heldStart = null;
    if (start !== null) this.playFrom(start);
  }

  private listen(): void {
    this.post({ event: 'listening', ...WIDGET });
  }

  private command(name: string, commandArguments: unknown[]): void {
    this.post({ event: 'command', func: name, args: commandArguments, ...WIDGET });
  }

  private post(message: object): void {
    this.frame.contentWindow?.postMessage(JSON.stringify(message), PLAYER_ORIGIN);
  }
}

function infoOf(data: unknown): PlayerInfo | null {
  if (typeof data !== 'string') return null;
  try {
    return (JSON.parse(data) as { info?: PlayerInfo } | null)?.info ?? null;
  } catch {
    return null;
  }
}

function secondsOf(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}
