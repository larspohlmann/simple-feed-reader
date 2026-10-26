import { hiddenPlaybackFrame } from './hidden-frame';
import { PlaybackElement, PlaybackEvent } from './playback-element';

const UNPLAYABLE = { code: 4, MEDIA_ERR_SRC_NOT_SUPPORTED: 4 } as const;

const NO_RANGES: TimeRanges = Object.freeze({
  length: 0,
  start: (): number => 0,
  end: (): number => 0,
});

function singleRange(end: number): TimeRanges {
  return { length: 1, start: () => 0, end: () => end };
}

export abstract class FramePlayback implements PlaybackElement {
  preload: PlaybackElement['preload'] = 'auto';
  defaultPlaybackRate = 1;
  protected frame: HTMLIFrameElement | null = null;
  private readonly listeners = new Map<PlaybackEvent, (() => void)[]>();
  private source = '';
  private time = 0;
  private length = 0;
  private loadedFraction = 0;
  private rate = 1;
  private loaded = false;
  private ready = false;
  /** A seek before the provider has played is held, because YouTube's seekTo on a cued video starts it. */
  private started = false;
  private paused = true;
  private failed = false;
  private startAt: number | null = null;

  private readonly onMessage = (event: MessageEvent): void => {
    if (!this.frame || event.origin !== this.origin) return;
    if (event.source !== this.frame.contentWindow) return;
    if (!this.ready && this.isReadyMessage(event.data)) this.becameReady();
    this.readMessage(event.data);
  };

  constructor(
    private readonly host: HTMLElement,
    private readonly origin: string,
    private readonly title: string,
  ) {
    window.addEventListener('message', this.onMessage);
  }

  get src(): string {
    return this.source;
  }

  set src(url: string) {
    this.source = url;
    this.resetState();
    this.dropFrame();
  }

  get currentTime(): number {
    return this.time;
  }

  set currentTime(seconds: number) {
    this.time = seconds;
    if (!this.started) {
      this.startAt = seconds;
      return;
    }
    this.seekTo(seconds);
  }

  get duration(): number {
    return this.hasLength() ? this.length : NaN;
  }

  get buffered(): TimeRanges {
    if (!this.hasLength() || this.loadedFraction <= 0) return NO_RANGES;
    return singleRange(this.loadedFraction * this.length);
  }

  get readyState(): number {
    return this.hasLength() ? HTMLMediaElement.HAVE_METADATA : HTMLMediaElement.HAVE_NOTHING;
  }

  get error(): PlaybackElement['error'] {
    return this.failed ? UNPLAYABLE : null;
  }

  get playbackRate(): number {
    return this.rate;
  }

  set playbackRate(rate: number) {
    this.rate = rate;
    if (this.ready) this.applyRate();
  }

  play(): Promise<void> {
    if (this.paused) {
      this.paused = false;
      this.emit('play');
    }
    if (!this.frame) this.mountFrame();
    if (this.ready) {
      this.startFromHeldPosition();
    } else {
      this.promptProvider();
    }
    return Promise.resolve();
  }

  pause(): void {
    if (this.ready) {
      this.stop();
      return;
    }
    if (this.paused) return;
    this.paused = true;
    this.emit('pause');
  }

  load(): void {
    this.resetState();
    this.dropFrame();
    this.mountFrame();
  }

  addEventListener(type: PlaybackEvent, listener: () => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
  }

  dispose(): void {
    window.removeEventListener('message', this.onMessage);
    this.dropFrame();
    this.listeners.clear();
  }

  protected abstract frameUrlFor(url: string): string;
  protected abstract isReadyMessage(data: unknown): boolean;
  protected abstract readMessage(data: unknown): void;
  protected abstract start(seconds: number | null): void;
  protected abstract stop(): void;
  protected abstract seekTo(seconds: number): void;

  /** Asked once the frame has loaded, and again for every play before the provider is ready. */
  protected promptProvider(): void {
    /* Most providers speak unprompted. */
  }

  /** Hands `playbackRate` to the provider; one without a rate control keeps its own pace. */
  protected applyRate(): void {
    /* Nothing to send. */
  }

  protected post(message: object): void {
    if (!this.loaded || !this.frame) return;
    this.frame.contentWindow?.postMessage(JSON.stringify(message), this.origin);
  }

  protected becameReady(): void {
    if (this.ready) return;
    this.ready = true;
    this.applyRate();
    if (this.paused) return;
    this.startFromHeldPosition();
  }

  protected reportTime(seconds: number): void {
    this.time = seconds;
    this.emit('timeupdate');
  }

  protected reportDuration(seconds: number): void {
    if (seconds === this.length) return;
    this.length = seconds;
    this.emit('loadedmetadata');
    this.emit('durationchange');
  }

  protected reportLoaded(fraction: number): void {
    if (fraction === this.loadedFraction) return;
    this.loadedFraction = fraction;
    this.emit('progress');
  }

  protected reportPlaying(): void {
    this.started = true;
    if (!this.paused) return;
    this.paused = false;
    this.emit('play');
  }

  protected reportPaused(): void {
    if (this.paused) return;
    this.paused = true;
    this.emit('pause');
  }

  protected reportEnded(): void {
    this.reportPaused();
    this.emit('ended');
  }

  protected reportFailure(): void {
    this.failed = true;
    this.emit('error');
  }

  private mountFrame(): void {
    if (!this.source) return;
    const frame = hiddenPlaybackFrame(this.frameUrlFor(this.source), this.title);
    frame.addEventListener('load', () => {
      if (frame !== this.frame) return;
      this.loaded = true;
      this.promptProvider();
    });
    this.frame = frame;
    this.host.appendChild(frame);
  }

  private dropFrame(): void {
    this.frame?.remove();
    this.frame = null;
  }

  private startFromHeldPosition(): void {
    const seconds = this.startAt;
    this.startAt = null;
    this.start(seconds);
  }

  private resetState(): void {
    this.time = 0;
    this.length = 0;
    this.loadedFraction = 0;
    this.loaded = false;
    this.ready = false;
    this.started = false;
    this.paused = true;
    this.failed = false;
    this.startAt = null;
  }

  private hasLength(): boolean {
    return this.length > 0;
  }

  private emit(type: PlaybackEvent): void {
    for (const listener of this.listeners.get(type) ?? []) listener();
  }
}
