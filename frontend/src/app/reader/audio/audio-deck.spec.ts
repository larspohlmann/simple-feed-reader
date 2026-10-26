import { WATCH_URL, WIDGET_URL } from '../../../testing/embed-media-testing';
import { FakeAudio } from '../../../testing/fake-audio-element';
import { AudioDeck } from './audio-deck';
import { PlaybackEvent, PlaybackSlot } from './playback/playback-element';
import { PlaybackKind } from './playback/playback-kind';
import { PlaybackSlotFactory } from './playback/playback-slots';

const EPISODE_URL = 'https://x.test/ep.mp3';
const NEXT_EPISODE_URL = 'https://x.test/next.mp3';
const DURATION = 3600;

interface RecordedSlot extends PlaybackSlot {
  readonly kind: PlaybackKind;
  readonly element: FakeAudio;
  disposed: boolean;
}

function recordingSlots(): { created: RecordedSlot[]; createSlot: PlaybackSlotFactory } {
  const created: RecordedSlot[] = [];
  const createSlot: PlaybackSlotFactory = (kind) => {
    const slot: RecordedSlot = {
      kind,
      element: new FakeAudio(),
      disposed: false,
      dispose: () => {
        slot.disposed = true;
      },
    };
    created.push(slot);
    return slot;
  };
  return { created, createSlot };
}

function kindsOf(slots: RecordedSlot[]): PlaybackKind[] {
  return slots.map((slot) => slot.kind);
}

describe('AudioDeck', () => {
  let created: RecordedSlot[];
  let heard: PlaybackEvent[];
  let deck: AudioDeck;

  beforeEach(() => {
    heard = [];
  });

  function make(): AudioDeck {
    const slots = recordingSlots();
    created = slots.created;
    deck = new AudioDeck(slots.createSlot, {
      progress: () => heard.push('progress'),
      timeupdate: () => heard.push('timeupdate'),
      loadedmetadata: () => heard.push('loadedmetadata'),
      durationchange: () => heard.push('durationchange'),
      play: () => heard.push('play'),
      pause: () => heard.push('pause'),
      ended: () => heard.push('ended'),
      error: () => heard.push('error'),
    });
    return deck;
  }

  function cacheNext(url: string): void {
    deck.cacheNearTheEnd(url, DURATION, DURATION);
  }

  it('starts with two file slots, both preloading', () => {
    make();

    expect(kindsOf(created)).toEqual(['file', 'file']);
    expect(deck.element).toBe(created[0].element);
    expect(created.map((slot) => slot.element.preload)).toEqual(['auto', 'auto']);
  });

  it('loads a file url into the playing file slot', () => {
    make();

    expect(deck.load(EPISODE_URL)).toBe(false);

    expect(created[0].element.src).toBe(EPISODE_URL);
    expect(created).toHaveLength(2);
  });

  describe('a track of another kind', () => {
    it('holds a youtube slot in place of the playing file slot', () => {
      make();

      expect(deck.load(WATCH_URL)).toBe(false);

      expect(created[0].disposed).toBe(true);
      expect(kindsOf(created)).toEqual(['file', 'file', 'youtube']);
      expect(deck.element).toBe(created[2].element);
      expect(created[2].element.src).toBe(WATCH_URL);
      expect(created[2].element.preload).toBe('auto');
    });

    it('leaves the standby slot as it is', () => {
      make();

      deck.load(WATCH_URL);

      expect(created[1].disposed).toBe(false);
      expect(created[1].kind).toBe('file');
    });

    it('plays the slot held later at the current rate', () => {
      make();
      deck.setRate(1.5);

      deck.load(WATCH_URL);

      expect(created[2].element.playbackRate).toBe(1.5);
      expect(created[2].element.defaultPlaybackRate).toBe(1.5);
    });

    it('hears the slot held later', () => {
      make();
      deck.load(WATCH_URL);

      created[2].element.fire('timeupdate');

      expect(heard).toEqual(['timeupdate']);
    });

    it('replaces the standby slot when it caches a soundcloud url', () => {
      make();
      deck.load(EPISODE_URL);

      cacheNext(WIDGET_URL);

      expect(created[1].disposed).toBe(true);
      expect(kindsOf(created)).toEqual(['file', 'file', 'soundcloud']);
      expect(created[2].element.src).toBe(WIDGET_URL);
      expect(deck.element).toBe(created[0].element);
    });

    it('keeps a standby slot that already plays the cached kind', () => {
      make();
      deck.load(EPISODE_URL);

      cacheNext(NEXT_EPISODE_URL);

      expect(created).toHaveLength(2);
      expect(created[1].element.src).toBe(NEXT_EPISODE_URL);
    });

    it('swaps into the cached frame slot and keeps the outgoing file slot on standby', () => {
      make();
      deck.load(EPISODE_URL);
      cacheNext(WATCH_URL);

      expect(deck.load(WATCH_URL)).toBe(true);

      expect(deck.element).toBe(created[2].element);
      expect(created[0].element.paused).toBe(true);
      expect(created[0].disposed).toBe(false);
      expect(deck.load(EPISODE_URL)).toBe(true);
      expect(deck.element).toBe(created[0].element);
    });
  });

  it('caches nothing while the playing track is far from its end', () => {
    make();
    deck.load(EPISODE_URL);

    deck.cacheNearTheEnd(NEXT_EPISODE_URL, DURATION, 600);

    expect(created[1].element.src).toBe('');
  });

  it('hears only the playing slot', () => {
    make();

    created[1].element.fire('play');
    created[0].element.fire('play');

    expect(heard).toEqual(['play']);
  });

  it('stops hearing a slot that moved to standby', () => {
    make();
    deck.load(EPISODE_URL);
    cacheNext(NEXT_EPISODE_URL);
    deck.load(NEXT_EPISODE_URL);

    created[0].element.fire('timeupdate');
    created[1].element.fire('timeupdate');

    expect(heard).toEqual(['timeupdate']);
    expect(deck.element).toBe(created[1].element);
  });

  describe('release', () => {
    it('disposes the standby slot and holds a fresh file slot', () => {
      make();
      deck.load(EPISODE_URL);
      cacheNext(WATCH_URL);

      deck.release();

      expect(created[2].disposed).toBe(true);
      expect(created[0].disposed).toBe(false);
      expect(kindsOf(created)).toEqual(['file', 'file', 'youtube', 'file']);
      expect(created[3].element.preload).toBe('auto');
    });

    it('lets go of a provider frame left playing', () => {
      make();
      deck.load(WATCH_URL);

      deck.release();

      expect(created[2].disposed).toBe(true);
      expect(kindsOf(created)).toEqual(['file', 'file', 'youtube', 'file', 'file']);
      expect(deck.element).toBe(created[3].element);
    });

    it('forgets the released urls, so the same track loads afresh', () => {
      make();
      deck.load(EPISODE_URL);
      cacheNext(NEXT_EPISODE_URL);
      deck.release();

      expect(deck.load(NEXT_EPISODE_URL)).toBe(false);
      expect(created[0].element.src).toBe(NEXT_EPISODE_URL);
    });

    it('plays the fresh slot at the current rate', () => {
      make();
      deck.setRate(2);

      deck.release();

      expect(created[2].element.playbackRate).toBe(2);
    });
  });

  it('sets the rate on both slots, the default rate too', () => {
    make();

    deck.setRate(1.25);

    for (const slot of created) {
      expect(slot.element.playbackRate).toBe(1.25);
      expect(slot.element.defaultPlaybackRate).toBe(1.25);
    }
  });
});
