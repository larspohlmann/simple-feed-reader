import {
  finiteNumberOf,
  LISTENING,
  playerCommand,
  playerInfoOf,
  playerMessageOf,
  PlayerMessage,
  postToPlayerFrame,
  YOUTUBE_PLAYER_ORIGIN,
} from '../media/youtube-player-protocol';
import { ChapterPlayer, PlaybackPosition } from './chapter-player';

/** Drives a YouTube embed through its iframe API; the frame must be loaded with `enablejsapi=1` (#1808). */
export class YouTubeChapterPlayer implements ChapterPlayer {
  private onPosition: ((position: PlaybackPosition | null) => void) | null = null;
  private durationSeconds: number | null = null;
  private ready = false;
  private heldStart: number | null = null;

  private readonly onMessage = (event: MessageEvent): void => {
    if (event.origin !== YOUTUBE_PLAYER_ORIGIN || event.source !== this.frame.contentWindow) return;
    const playsHeldStart = !this.ready && this.heldStart !== null;
    this.becomeReady();
    const info = playerInfoOf(playerMessageOf(event.data));
    this.rememberDuration(info);
    if (playsHeldStart) return;
    const seconds = finiteNumberOf(info['currentTime']);
    if (seconds !== null) this.onPosition?.({ seconds, durationSeconds: this.durationSeconds });
  };

  private readonly onLoad = (): void => this.post(LISTENING);

  constructor(private readonly frame: HTMLIFrameElement) {
    window.addEventListener('message', this.onMessage);
    frame.addEventListener('load', this.onLoad);
  }

  playFrom(seconds: number): void {
    if (!this.ready) {
      this.heldStart = seconds;
      this.post(LISTENING);
      return;
    }
    this.post(playerCommand('seekTo', [seconds, true]));
    this.post(playerCommand('playVideo', []));
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

  private rememberDuration(info: PlayerMessage): void {
    const duration = finiteNumberOf(info['duration']);
    if (duration !== null && duration > 0) this.durationSeconds = duration;
  }

  private post(message: object): void {
    postToPlayerFrame(this.frame, message);
  }
}
