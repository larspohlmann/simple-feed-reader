import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

type Coordinator = Pick<ListAutoplayCoordinator, 'nowPlaying' | 'soundChanged'>;

export class VideoAutoplayPlayer implements AutoplayPlayer {
  private pendingOwnChanges = 0;

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
    this.pendingOwnChanges++;
    this.element.muted = muted;
  }

  private reportSound(): void {
    if (this.pendingOwnChanges > 0) {
      this.pendingOwnChanges--;
      return;
    }
    this.coordinator.soundChanged(!this.element.muted);
  }
}

/** Only a refused autoplay earns the muted retry; an HLS source swap aborts the first play too. */
function isRefusal(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'NotAllowedError';
}
