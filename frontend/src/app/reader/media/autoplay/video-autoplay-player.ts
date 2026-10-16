import { AutoplayPlayer } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

type Coordinator = Pick<ListAutoplayCoordinator, 'nowPlaying' | 'soundChanged'>;

export class VideoAutoplayPlayer implements AutoplayPlayer {
  private pendingOwnChanges = 0;
  private wanted = false;
  private readonly listeners = new AbortController();

  constructor(
    readonly element: HTMLVideoElement,
    private readonly coordinator: Coordinator,
  ) {
    const { signal } = this.listeners;
    element.addEventListener('play', () => coordinator.nowPlaying(this), { signal });
    element.addEventListener('volumechange', () => this.reportSound(), { signal });
  }

  start(withSound: boolean): void {
    this.wanted = true;
    this.setMuted(!withSound);
    this.element.play().catch((error: unknown) => {
      if (withSound && this.wanted && isRefusal(error)) this.start(false);
    });
  }

  stop(): void {
    this.wanted = false;
    this.element.pause();
  }

  dispose(): void {
    this.listeners.abort();
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
