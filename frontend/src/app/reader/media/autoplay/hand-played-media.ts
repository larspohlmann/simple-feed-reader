import { PlayingMedium } from './autoplay-player';
import { ListAutoplayCoordinator } from './list-autoplay-coordinator';

type Coordinator = Pick<ListAutoplayCoordinator, 'nowPlaying' | 'stopped'>;

/** The article body's videos and audios: they never autoplay, and the coordinator learns when the
 *  user plays one, so it stops the rest and holds autoplay until that one stops. */
export class HandPlayedMedia {
  private readonly followed = new Map<HTMLMediaElement, HandPlayedMedium>();

  constructor(private readonly coordinator: Coordinator) {}

  follow(root: ParentNode): void {
    const present = new Set(root.querySelectorAll<HTMLMediaElement>('video, audio'));
    this.forgetWhere((element) => !present.has(element));
    for (const element of present) {
      if (!this.followed.has(element)) {
        this.followed.set(element, new HandPlayedMedium(element, this.coordinator));
      }
    }
  }

  forgetAll(): void {
    this.forgetWhere(() => true);
  }

  private forgetWhere(shouldForget: (element: HTMLMediaElement) => boolean): void {
    for (const [element, medium] of this.followed) {
      if (!shouldForget(element)) continue;
      medium.dispose();
      this.followed.delete(element);
    }
  }
}

class HandPlayedMedium implements PlayingMedium {
  private readonly listeners = new AbortController();

  constructor(
    readonly element: HTMLMediaElement,
    private readonly coordinator: Coordinator,
  ) {
    const { signal } = this.listeners;
    element.addEventListener('play', () => this.played(), { signal });
    element.addEventListener('pause', () => coordinator.stopped(this), { signal });
    element.addEventListener('ended', () => coordinator.stopped(this), { signal });
  }

  stop(): void {
    this.element.pause();
  }

  dispose(): void {
    this.listeners.abort();
    this.coordinator.stopped(this);
  }

  private played(): void {
    if (!this.element.paused) this.coordinator.nowPlaying(this);
  }
}
