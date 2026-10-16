import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

type Coordinator = Pick<ListAutoplayCoordinator, 'nowPlaying' | 'soundChanged'>;

export class VideoAutoplayPlayer implements AutoplayPlayer {
  /** The muted state this player just set itself, whose `volumechange` is not the user's. */
  private ownMuted: boolean | null = null;

  constructor(
    readonly element: HTMLVideoElement,
    private readonly coordinator: Coordinator,
  ) {
    element.addEventListener('play', () => coordinator.nowPlaying(this));
    element.addEventListener('volumechange', () => this.reportSound());
  }

  start(withSound: boolean): void {
    this.setMuted(!withSound);
    this.element.play().catch((error: unknown) => {
      if (withSound && isRefusal(error)) this.start(false);
    });
  }

  stop(): void {
    this.element.pause();
  }

  private setMuted(muted: boolean): void {
    if (this.element.muted === muted) return;
    this.ownMuted = muted;
    this.element.muted = muted;
  }

  private reportSound(): void {
    const own = this.ownMuted === this.element.muted;
    this.ownMuted = null;
    if (!own) this.coordinator.soundChanged(!this.element.muted);
  }
}

/** Only a refused autoplay earns the muted retry; an HLS source swap aborts the first play too. */
function isRefusal(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'NotAllowedError';
}
