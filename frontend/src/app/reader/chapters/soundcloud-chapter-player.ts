import {
  postToWidgetFrame,
  SOUNDCLOUD_WIDGET_ORIGIN,
  widgetFieldOf,
  widgetMessage,
  widgetMessageOf,
} from '../media/soundcloud-widget-protocol';
import { finiteNumberOf } from '../media/youtube-player-protocol';
import { ChapterPlayer, PlaybackPosition } from './chapter-player';

const SUBSCRIBED_EVENTS = ['playProgress', 'play', 'pause'];
const MILLISECONDS = 1000;

/** Drives a visible SoundCloud widget through its Widget API; no ready handshake is needed (#1824). */
export class SoundCloudChapterPlayer implements ChapterPlayer {
  private onPosition: ((position: PlaybackPosition | null) => void) | null = null;
  private durationSeconds: number | null = null;
  private playing = false;
  private heldStart: number | null = null;

  private readonly onMessage = (event: MessageEvent): void => {
    if (event.origin !== SOUNDCLOUD_WIDGET_ORIGIN || event.source !== this.frame.contentWindow) {
      return;
    }
    const message = widgetMessageOf(event.data);
    if (message === null) return;
    switch (message.method) {
      case 'getDuration':
        return this.readDuration(message.value);
      case 'playProgress':
        return this.readPosition(message.value);
      case 'play':
        return this.becomePlaying();
      case 'pause':
        this.playing = false;
        return;
      default:
        return;
    }
  };

  private readonly onLoad = (): void => {
    this.playing = false;
    this.subscribe();
  };

  constructor(private readonly frame: HTMLIFrameElement) {
    window.addEventListener('message', this.onMessage);
    frame.addEventListener('load', this.onLoad);
    this.subscribe();
  }

  playFrom(seconds: number): void {
    if (this.playing) {
      this.seekTo(seconds);
      return;
    }
    this.heldStart = seconds;
    this.post(widgetMessage('play'));
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

  private subscribe(): void {
    for (const event of SUBSCRIBED_EVENTS) this.post(widgetMessage('addEventListener', event));
    this.post(widgetMessage('getDuration'));
  }

  private readDuration(value: unknown): void {
    const milliseconds = finiteNumberOf(value);
    if (milliseconds !== null && milliseconds > 0)
      this.durationSeconds = milliseconds / MILLISECONDS;
  }

  private readPosition(value: unknown): void {
    const milliseconds = finiteNumberOf(widgetFieldOf(value, 'currentPosition'));
    if (milliseconds === null) return;
    this.becomePlaying();
    this.onPosition?.({
      seconds: milliseconds / MILLISECONDS,
      durationSeconds: this.durationSeconds,
    });
  }

  private becomePlaying(): void {
    this.playing = true;
    const start = this.heldStart;
    this.heldStart = null;
    if (start !== null) this.seekTo(start);
  }

  private seekTo(seconds: number): void {
    this.post(widgetMessage('seekTo', Math.round(seconds * MILLISECONDS)));
  }

  private post(message: ReturnType<typeof widgetMessage>): void {
    postToWidgetFrame(this.frame, message);
  }
}
