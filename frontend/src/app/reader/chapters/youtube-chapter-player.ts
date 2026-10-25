import { ChapterPlayer } from './chapter-player';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';
const WIDGET = { id: 1, channel: 'widget' };

/** Drives a YouTube embed through its iframe API; the frame must be loaded with `enablejsapi=1` (#1808). */
export class YouTubeChapterPlayer implements ChapterPlayer {
  private onPosition: ((seconds: number | null) => void) | null = null;

  private readonly onMessage = (event: MessageEvent): void => {
    if (event.origin !== PLAYER_ORIGIN || event.source !== this.frame.contentWindow) return;
    const seconds = currentTimeOf(event.data);
    if (seconds !== null) this.onPosition?.(seconds);
  };

  private readonly onLoad = (): void => this.post({ event: 'listening', ...WIDGET });

  constructor(private readonly frame: HTMLIFrameElement) {
    window.addEventListener('message', this.onMessage);
    frame.addEventListener('load', this.onLoad);
  }

  playFrom(seconds: number): void {
    this.command('seekTo', [seconds, true]);
    this.command('playVideo', []);
  }

  watch(onPosition: (seconds: number | null) => void): void {
    this.onPosition = onPosition;
  }

  release(): void {
    this.onPosition = null;
    window.removeEventListener('message', this.onMessage);
    this.frame.removeEventListener('load', this.onLoad);
  }

  private command(name: string, commandArguments: unknown[]): void {
    this.post({ event: 'command', func: name, args: commandArguments, ...WIDGET });
  }

  private post(message: object): void {
    this.frame.contentWindow?.postMessage(JSON.stringify(message), PLAYER_ORIGIN);
  }
}

function currentTimeOf(data: unknown): number | null {
  if (typeof data !== 'string') return null;
  try {
    const time = (JSON.parse(data) as { info?: { currentTime?: unknown } } | null)?.info
      ?.currentTime;
    return typeof time === 'number' ? time : null;
  } catch {
    return null;
  }
}
