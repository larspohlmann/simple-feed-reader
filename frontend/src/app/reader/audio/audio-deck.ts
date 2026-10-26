import { PlaybackElement, PlaybackEvent, PlaybackSlot } from './playback/playback-element';
import { PlaybackKind, playbackKindOf } from './playback/playback-kind';
import { PlaybackSlotFactory } from './playback/playback-slots';

/** The next track starts caching once the playing one is this close to its end, or fully cached. */
export const PRECACHE_LEAD_SECONDS = 300;

export type PlaybackHandlers = Record<PlaybackEvent, () => void>;

interface HeldSlot extends PlaybackSlot {
  readonly kind: PlaybackKind;
  url: string | null;
}

/** The player's two playback slots: one playing, one caching the next track (#1429). A slot
 *  plays one kind of media (`playbackKindOf`) and is let go for a fresh one when a track's kind
 *  differs. An element's own `src` reads back normalised, so each slot's url is kept as queued. */
export class AudioDeck {
  private playing: HeldSlot;
  private standby: HeldSlot;
  private rate = 1;

  constructor(
    private readonly createSlot: PlaybackSlotFactory,
    private readonly handlers: PlaybackHandlers,
  ) {
    this.playing = this.hold('file');
    this.standby = this.hold('file');
  }

  get element(): PlaybackElement {
    return this.playing.element;
  }

  /** Points playback at `url`; true when the standby slot held it and took over, cache and all.
   *  The outgoing track stays on standby, so stepping back to it is cached too. */
  load(url: string): boolean {
    const outgoingUrl = this.playing.url;
    if (this.standby.url !== url) {
      this.playing = this.fitted(this.playing, url);
      this.playing.url = url;
      this.playing.element.src = url;
      return false;
    }
    const outgoing = this.playing;
    this.playing = this.standby;
    this.standby = outgoing;
    this.standby.url = outgoingUrl;
    outgoing.element.pause();
    return true;
  }

  /** Caches `url` on standby once the playing track (`duration`, cached to `buffered`) nears its end. */
  cacheNearTheEnd(url: string, duration: number, buffered: number): void {
    if (url === this.standby.url || duration <= 0) return;
    const remaining = duration - this.playing.element.currentTime;
    if (buffered < duration - 1 && remaining > PRECACHE_LEAD_SECONDS) return;
    this.standby = this.fitted(this.standby, url);
    this.standby.url = url;
    this.standby.element.src = url;
  }

  setRate(rate: number): void {
    this.rate = rate;
    for (const slot of [this.playing, this.standby]) this.applyRate(slot.element);
  }

  release(): void {
    this.playing.url = null;
    this.standby.dispose();
    this.standby = this.hold('file');
  }

  /** The slot itself when it plays `url`'s kind of media, else a fresh one of that kind in its place. */
  private fitted(slot: HeldSlot, url: string): HeldSlot {
    const kind = playbackKindOf(url);
    if (slot.kind === kind) return slot;
    slot.dispose();
    return this.hold(kind);
  }

  private hold(kind: PlaybackKind): HeldSlot {
    const slot = this.createSlot(kind);
    slot.element.preload = 'auto';
    this.listen(slot.element);
    this.applyRate(slot.element);
    return { kind, url: null, element: slot.element, dispose: () => slot.dispose() };
  }

  /** Every slot is bound once; only the one playing is heard. */
  private listen(element: PlaybackElement): void {
    for (const type of Object.keys(this.handlers) as PlaybackEvent[]) {
      element.addEventListener(type, () => {
        if (element === this.playing.element) this.handlers[type]();
      });
    }
  }

  /** The default rate too: a new `src` resets `playbackRate` to it. */
  private applyRate(element: PlaybackElement): void {
    element.defaultPlaybackRate = this.rate;
    element.playbackRate = this.rate;
  }
}
