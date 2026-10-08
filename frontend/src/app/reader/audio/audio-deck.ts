/** The next track starts caching once the playing one is this close to its end, or fully cached. */
export const PRECACHE_LEAD_SECONDS = 300;

/** The player's two audio elements: one playing, one caching the next track (#1429).
 *  An element's own `src` reads back normalised, so each one's url is kept as queued. */
export class AudioDeck {
  private playing: HTMLAudioElement;
  private standby: HTMLAudioElement;
  private playingUrl: string | null = null;
  private standbyUrl: string | null = null;

  constructor(
    createElement: () => HTMLAudioElement,
    bind: (element: HTMLAudioElement, isPlaying: () => boolean) => void,
  ) {
    this.playing = createElement();
    this.standby = createElement();
    for (const element of [this.playing, this.standby]) {
      element.preload = 'auto';
      bind(element, () => element === this.playing);
    }
  }

  get element(): HTMLAudioElement {
    return this.playing;
  }

  /** Points playback at `url`; true when the standby element held it and took over, cache and all.
   *  The outgoing track stays on standby, so stepping back to it is cached too. */
  load(url: string): boolean {
    const outgoingUrl = this.playingUrl;
    this.playingUrl = url;
    if (this.standbyUrl !== url) {
      this.playing.src = url;
      return false;
    }
    const outgoing = this.playing;
    this.playing = this.standby;
    this.standby = outgoing;
    this.standbyUrl = outgoingUrl;
    outgoing.pause();
    return true;
  }

  /** Caches `url` on standby once the playing track (`duration`, cached to `buffered`) nears its end. */
  cacheNearTheEnd(url: string, duration: number, buffered: number): void {
    if (url === this.standbyUrl || duration <= 0) return;
    const remaining = duration - this.playing.currentTime;
    if (buffered < duration - 1 && remaining > PRECACHE_LEAD_SECONDS) return;
    this.standbyUrl = url;
    this.standby.src = url;
  }

  /** The default rate too: a new `src` resets `playbackRate` to it. */
  setRate(rate: number): void {
    for (const element of [this.playing, this.standby]) {
      element.defaultPlaybackRate = rate;
      element.playbackRate = rate;
    }
  }

  release(): void {
    this.playingUrl = null;
    this.standbyUrl = null;
    this.standby.removeAttribute('src');
    this.standby.load();
  }
}
