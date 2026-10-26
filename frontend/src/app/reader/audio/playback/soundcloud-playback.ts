import { FramePlayback } from './frame-playback';

const WIDGET_ORIGIN = 'https://w.soundcloud.com';
const WIDGET_FLAGS = 'auto_play=false&show_artwork=false&visual=false';
const SUBSCRIBED_EVENTS = ['play', 'pause', 'finish', 'playProgress', 'loadProgress', 'error'];
const MILLISECONDS = 1000;

interface WidgetMessage {
  readonly method: string;
  readonly value?: unknown;
}

/** The SoundCloud Widget API over the hidden frame: `{ method, value }` JSON both ways (#1815). */
export class SoundCloudPlayback extends FramePlayback {
  /** The widget seeks reliably only once the sound plays, so a start position waits for its first `play`. */
  private seekOnPlay: number | null = null;

  constructor(host: HTMLElement) {
    super(host, WIDGET_ORIGIN, 'SoundCloud');
  }

  protected frameUrlFor(url: string): string {
    return `${url}${url.includes('?') ? '&' : '?'}${WIDGET_FLAGS}`;
  }

  protected onFrameLoaded(): void {
    // The widget posts `ready` on its own.
  }

  protected isReadyMessage(data: unknown): boolean {
    return widgetMessageOf(data)?.method === 'ready';
  }

  protected override becameReady(): void {
    this.subscribe();
    super.becameReady();
  }

  protected readMessage(data: unknown): void {
    const message = widgetMessageOf(data);
    if (message === null) return;
    switch (message.method) {
      case 'getDuration':
        return this.readDuration(message.value);
      case 'playProgress':
        return this.readPosition(message.value);
      case 'loadProgress':
        return this.readLoaded(message.value);
      default:
        return this.readEvent(message.method);
    }
  }

  protected start(seconds: number | null): void {
    this.seekOnPlay = seconds !== null && seconds > 0 ? seconds : null;
    this.post({ method: 'play' });
  }

  protected stop(): void {
    this.post({ method: 'pause' });
  }

  protected seekTo(seconds: number): void {
    this.post({ method: 'seekTo', value: Math.round(seconds * MILLISECONDS) });
  }

  protected applyRate(): void {
    // The widget has no rate control; the speed applies to the other tracks.
  }

  private subscribe(): void {
    for (const event of SUBSCRIBED_EVENTS) this.post({ method: 'addEventListener', value: event });
    this.post({ method: 'getDuration' });
  }

  private readDuration(value: unknown): void {
    const milliseconds = finiteNumberOf(value);
    if (milliseconds !== null) this.reportDuration(milliseconds / MILLISECONDS);
  }

  private readPosition(value: unknown): void {
    const milliseconds = finiteNumberOf(fieldOf(value, 'currentPosition'));
    if (milliseconds !== null) this.reportTime(milliseconds / MILLISECONDS);
  }

  private readLoaded(value: unknown): void {
    const fraction = finiteNumberOf(fieldOf(value, 'loadedProgress'));
    if (fraction !== null) this.reportLoaded(fraction);
  }

  private readEvent(method: string): void {
    switch (method) {
      case 'play':
        return this.confirmPlaying();
      case 'pause':
        return this.reportPaused();
      case 'finish':
        return this.reportEnded();
      case 'error':
        return this.reportFailure();
      default:
        return;
    }
  }

  private confirmPlaying(): void {
    this.reportPlaying();
    if (this.seekOnPlay === null) return;
    this.seekTo(this.seekOnPlay);
    this.seekOnPlay = null;
  }
}

function widgetMessageOf(data: unknown): WidgetMessage | null {
  if (typeof data !== 'string') return null;
  try {
    const parsed: unknown = JSON.parse(data);
    return typeof fieldOf(parsed, 'method') === 'string' ? (parsed as WidgetMessage) : null;
  } catch {
    return null;
  }
}

function fieldOf(value: unknown, name: string): unknown {
  if (typeof value !== 'object' || value === null) return undefined;
  return (value as Record<string, unknown>)[name];
}

function finiteNumberOf(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}
