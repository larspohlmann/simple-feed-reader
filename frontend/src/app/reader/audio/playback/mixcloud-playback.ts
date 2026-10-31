import {
  GET_API,
  methodCall,
  MIXCLOUD_WIDGET_ORIGIN,
  MixcloudEvent,
  mixcloudEventOf,
  mixcloudMessageOf,
} from '../../media/mixcloud-widget-protocol';
import { finiteNumberOf } from '../../media/youtube-player-protocol';
import { FramePlayback } from './frame-playback';

/** A show the widget cannot play (a subscriber exclusive, a stalled stream) starts but never makes progress. */
const START_TIMEOUT_MS = 10_000;

/** The Mixcloud widget API over the hidden frame: `getApi` and `method` out, `{ mixcloud, type, data }` in (#1932). */
export class MixcloudPlayback extends FramePlayback {
  /** The widget plays, pauses and plays again while it starts; only a pause after its first progress is real. */
  private startingUp = false;
  /** A start position waits for the first progress: a seek before it does not land. */
  private seekOnProgress: number | null = null;
  private startWatchdog: ReturnType<typeof setTimeout> | undefined;

  constructor(host: HTMLElement) {
    super(host, MIXCLOUD_WIDGET_ORIGIN, 'Mixcloud');
  }

  override dispose(): void {
    this.cancelStartWatch();
    super.dispose();
  }

  protected frameUrlFor(url: string): string {
    return url;
  }

  protected override promptProvider(): void {
    this.post(GET_API);
  }

  protected isReadyMessage(data: unknown): boolean {
    return mixcloudMessageOf(data)?.type === 'api';
  }

  protected readMessage(data: unknown): void {
    const message = mixcloudMessageOf(data);
    if (message?.type === 'ready') return this.promptProvider();
    const event = message === null ? null : mixcloudEventOf(message);
    if (event !== null) this.readEvent(event);
  }

  protected start(seconds: number | null): void {
    this.startingUp = true;
    this.seekOnProgress = seconds !== null && seconds > 0 ? seconds : null;
    this.post(methodCall('play'));
    this.watchStart();
  }

  protected stop(): void {
    this.startingUp = false;
    this.cancelStartWatch();
    this.post(methodCall('pause'));
  }

  protected seekTo(seconds: number): void {
    this.post(methodCall('seek', [seconds]));
  }

  private readEvent({ eventName, args }: MixcloudEvent): void {
    switch (eventName) {
      case 'progress':
        return this.readProgress(args);
      case 'play':
        return this.reportPlaying();
      case 'pause':
        return this.readPause();
      case 'ended':
        this.cancelStartWatch();
        return this.reportEnded();
      case 'error':
        this.cancelStartWatch();
        return this.reportFailure();
      default:
        return;
    }
  }

  private readProgress([position, duration]: readonly unknown[]): void {
    const positionSeconds = finiteNumberOf(position);
    if (positionSeconds === null) return;
    const durationSeconds = finiteNumberOf(duration);
    if (durationSeconds !== null && durationSeconds > 0) this.reportDuration(durationSeconds);
    this.startingUp = false;
    this.cancelStartWatch();
    const target = this.seekOnProgress;
    this.seekOnProgress = null;
    if (target !== null) return this.seekTo(target);
    this.reportTime(positionSeconds);
  }

  private watchStart(): void {
    this.cancelStartWatch();
    const frame = this.frame;
    this.startWatchdog = setTimeout(() => {
      if (this.frame === frame) this.reportFailure();
    }, START_TIMEOUT_MS);
  }

  private cancelStartWatch(): void {
    clearTimeout(this.startWatchdog);
  }

  private readPause(): void {
    if (!this.startingUp) this.reportPaused();
  }
}
