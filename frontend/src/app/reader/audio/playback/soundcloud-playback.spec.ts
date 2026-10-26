import { PlaybackEvent } from './playback-element';
import { SoundCloudPlayback } from './soundcloud-playback';

const WIDGET_ORIGIN = 'https://w.soundcloud.com';
const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';
const WIDGET_FLAGS = 'auto_play=false&show_artwork=false&visual=false';
const ALL_EVENTS: PlaybackEvent[] = [
  'progress',
  'timeupdate',
  'loadedmetadata',
  'durationchange',
  'play',
  'pause',
  'ended',
  'error',
];
const READY_MESSAGES = [
  { method: 'addEventListener', value: 'play' },
  { method: 'addEventListener', value: 'pause' },
  { method: 'addEventListener', value: 'finish' },
  { method: 'addEventListener', value: 'playProgress' },
  { method: 'addEventListener', value: 'loadProgress' },
  { method: 'addEventListener', value: 'error' },
  { method: 'getDuration' },
];

interface Fixture {
  playback: SoundCloudPlayback;
  host: HTMLElement;
  events: PlaybackEvent[];
  frame: HTMLIFrameElement;
  postMessage: jest.Mock;
}

function frameOf(host: HTMLElement): HTMLIFrameElement {
  const frame = host.querySelector('iframe');
  if (!frame) throw new Error('no frame in the host');
  return frame;
}

/** Replaces the frame's window with one that records what is posted to it. */
function recordPosts(frame: HTMLIFrameElement): jest.Mock {
  const postMessage = jest.fn();
  Object.defineProperty(frame, 'contentWindow', { value: { postMessage }, configurable: true });
  return postMessage;
}

function postedTo(postMessage: jest.Mock): unknown[] {
  return postMessage.mock.calls.map(([message]: [string]) => JSON.parse(message) as unknown);
}

function targetsOf(postMessage: jest.Mock): string[] {
  return postMessage.mock.calls.map(([, origin]: [string, string]) => origin);
}

/** jsdom accepts only a real Window as a MessageEvent source, so the fake one is set afterwards. */
function delivery(frame: HTMLIFrameElement, data: unknown, origin = WIDGET_ORIGIN): void {
  const event = new MessageEvent('message', { data, origin });
  Object.defineProperty(event, 'source', { value: frame.contentWindow });
  window.dispatchEvent(event);
}

function widgetMessage(frame: HTMLIFrameElement, method: string, value?: unknown): void {
  delivery(frame, JSON.stringify(value === undefined ? { method } : { method, value }));
}

function playbackWithSource(url = WIDGET_URL): Fixture {
  const host = document.createElement('div');
  document.body.appendChild(host);
  const playback = new SoundCloudPlayback(host);
  const events: PlaybackEvent[] = [];
  for (const type of ALL_EVENTS) playback.addEventListener(type, () => events.push(type));
  playback.src = url;
  const frame = frameOf(host);
  const postMessage = recordPosts(frame);
  return { playback, host, events, frame, postMessage };
}

function loadedPlayback(url?: string): Fixture {
  const fixture = playbackWithSource(url);
  fixture.frame.dispatchEvent(new Event('load'));
  return fixture;
}

function readyPlayback(url?: string): Fixture {
  const fixture = loadedPlayback(url);
  widgetMessage(fixture.frame, 'ready');
  return fixture;
}

describe('SoundCloudPlayback', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  describe('frame', () => {
    it('loads the widget url with autoplay, artwork and the visual player off', () => {
      const { playback, frame } = playbackWithSource();

      expect(frame.getAttribute('src')).toBe(`${WIDGET_URL}&${WIDGET_FLAGS}`);
      expect(frame.getAttribute('title')).toBe('SoundCloud');
      playback.dispose();
    });

    it('starts the query itself for a widget url without one', () => {
      const { playback, frame } = playbackWithSource('https://w.soundcloud.com/player/');

      expect(frame.getAttribute('src')).toBe(`https://w.soundcloud.com/player/?${WIDGET_FLAGS}`);
      playback.dispose();
    });

    it('posts nothing on load, the widget announces itself', () => {
      const { playback, postMessage } = loadedPlayback();

      expect(postMessage).not.toHaveBeenCalled();
      playback.dispose();
    });

    it('subscribes to the widget events and asks for the length once the widget is ready', () => {
      const { playback, postMessage } = readyPlayback();

      expect(postedTo(postMessage)).toEqual(READY_MESSAGES);
      expect(new Set(targetsOf(postMessage))).toEqual(new Set([WIDGET_ORIGIN]));
      playback.dispose();
    });

    it('becomes ready once, on the ready message only', () => {
      const { playback, frame, postMessage } = loadedPlayback();

      widgetMessage(frame, 'loadProgress', { loadedProgress: 0.1 });
      expect(postMessage).not.toHaveBeenCalled();

      widgetMessage(frame, 'ready');
      widgetMessage(frame, 'ready');

      expect(postedTo(postMessage)).toEqual(READY_MESSAGES);
      playback.dispose();
    });
  });

  describe('play', () => {
    it('plays once the widget is ready when asked before', async () => {
      const { playback, frame, events, postMessage } = loadedPlayback();

      await playback.play();

      expect(events).toEqual(['play']);
      expect(postMessage).not.toHaveBeenCalled();

      widgetMessage(frame, 'ready');

      expect(postedTo(postMessage)).toEqual([...READY_MESSAGES, { method: 'play' }]);
      playback.dispose();
    });

    it('plays at once when the widget is ready', async () => {
      const { playback, postMessage } = readyPlayback();

      await playback.play();

      expect(postedTo(postMessage)).toEqual([...READY_MESSAGES, { method: 'play' }]);
      playback.dispose();
    });

    it('seeks to a restored position in milliseconds on the first play event, and only once', async () => {
      const { playback, frame, postMessage } = readyPlayback();
      playback.currentTime = 0;
      playback.currentTime = 256.5;

      await playback.play();
      expect(postedTo(postMessage)).toEqual([...READY_MESSAGES, { method: 'play' }]);

      widgetMessage(frame, 'play');
      widgetMessage(frame, 'play');

      expect(postedTo(postMessage)).toEqual([
        ...READY_MESSAGES,
        { method: 'play' },
        { method: 'seekTo', value: 256500 },
      ]);
      playback.dispose();
    });

    it('starts from the beginning without a seek', async () => {
      const { playback, frame, postMessage } = readyPlayback();
      playback.currentTime = 0;

      await playback.play();
      widgetMessage(frame, 'play');

      expect(postedTo(postMessage)).toEqual([...READY_MESSAGES, { method: 'play' }]);
      playback.dispose();
    });

    it('forgets the restored position after a later play', async () => {
      const { playback, frame, postMessage } = readyPlayback();
      playback.currentTime = 12;
      await playback.play();
      widgetMessage(frame, 'play');
      playback.pause();
      widgetMessage(frame, 'pause');

      await playback.play();
      widgetMessage(frame, 'play');

      expect(postedTo(postMessage)).toEqual([
        ...READY_MESSAGES,
        { method: 'play' },
        { method: 'seekTo', value: 12000 },
        { method: 'pause' },
        { method: 'play' },
      ]);
      playback.dispose();
    });

    it('emits play when the widget starts on its own', () => {
      const { playback, frame, events } = readyPlayback();

      widgetMessage(frame, 'play');
      widgetMessage(frame, 'play');

      expect(events).toEqual(['play']);
      playback.dispose();
    });
  });

  describe('pause', () => {
    it('pauses the widget and emits pause when the widget confirms', async () => {
      const { playback, frame, events, postMessage } = readyPlayback();
      await playback.play();
      widgetMessage(frame, 'play');

      playback.pause();
      expect(postedTo(postMessage)).toEqual([
        ...READY_MESSAGES,
        { method: 'play' },
        { method: 'pause' },
      ]);
      expect(events).toEqual(['play']);

      widgetMessage(frame, 'pause');

      expect(events).toEqual(['play', 'pause']);
      playback.dispose();
    });
  });

  describe('seeking', () => {
    it('seeks in whole milliseconds once the sound plays', () => {
      const { playback, frame, postMessage } = readyPlayback();
      widgetMessage(frame, 'play');

      playback.currentTime = 30;
      playback.currentTime = 12.3456;

      expect(postedTo(postMessage)).toEqual([
        ...READY_MESSAGES,
        { method: 'seekTo', value: 30000 },
        { method: 'seekTo', value: 12346 },
      ]);
      playback.dispose();
    });

    it('reads the position the widget reports in seconds and emits timeupdate', () => {
      const { playback, frame, events } = readyPlayback();

      widgetMessage(frame, 'playProgress', { currentPosition: 42500, relativePosition: 0.1 });

      expect(playback.currentTime).toBe(42.5);
      expect(events).toEqual(['timeupdate']);
      playback.dispose();
    });
  });

  describe('duration and buffering', () => {
    it('reads the length the widget answers in seconds', () => {
      const { playback, frame, events } = readyPlayback();

      widgetMessage(frame, 'getDuration', 600000);

      expect(playback.duration).toBe(600);
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_METADATA);
      expect(events).toEqual(['loadedmetadata', 'durationchange']);
      playback.dispose();
    });

    it('spans the buffered range over the loaded share of the length', () => {
      const { playback, frame, events } = readyPlayback();
      widgetMessage(frame, 'getDuration', 600000);

      widgetMessage(frame, 'loadProgress', { loadedProgress: 0.25 });

      expect(playback.buffered.length).toBe(1);
      expect(playback.buffered.end(0)).toBe(150);
      expect(events).toEqual(['loadedmetadata', 'durationchange', 'progress']);
      playback.dispose();
    });
  });

  describe('ending and failing', () => {
    it('emits pause, then ended when the sound finishes', () => {
      const { playback, frame, events } = readyPlayback();
      widgetMessage(frame, 'play');

      widgetMessage(frame, 'finish');

      expect(events).toEqual(['play', 'pause', 'ended']);
      playback.dispose();
    });

    it('marks the element dead on a widget error', () => {
      const { playback, frame, events } = readyPlayback();

      widgetMessage(frame, 'error');

      expect(playback.error?.code).toBe(4);
      expect(events).toEqual(['error']);
      playback.dispose();
    });
  });

  describe('playback rate', () => {
    it('sends no rate, the widget has none', () => {
      const { playback, frame, postMessage } = loadedPlayback();
      playback.playbackRate = 1.5;
      widgetMessage(frame, 'ready');

      playback.playbackRate = 2;

      expect(postedTo(postMessage)).toEqual(READY_MESSAGES);
      expect(playback.playbackRate).toBe(2);
      playback.dispose();
    });
  });

  describe('unwanted messages', () => {
    it('accepts nothing from the soundcloud.com site itself', () => {
      const { playback, frame, events, postMessage } = loadedPlayback();

      delivery(frame, JSON.stringify({ method: 'ready' }), 'https://soundcloud.com');
      delivery(frame, JSON.stringify({ method: 'error' }), 'https://soundcloud.com');

      expect(postMessage).not.toHaveBeenCalled();
      expect(events).toEqual([]);
      playback.dispose();
    });

    it('ignores what is not a widget message', () => {
      const { playback, frame, events, postMessage } = loadedPlayback();

      delivery(frame, { method: 'ready' });
      delivery(frame, '{not json');
      delivery(frame, JSON.stringify(null));
      delivery(frame, JSON.stringify({ value: 'ready' }));
      delivery(frame, JSON.stringify({ method: 'getDuration', value: 'long' }));
      delivery(frame, JSON.stringify({ method: 'playProgress', value: null }));
      delivery(frame, JSON.stringify({ method: 'loadProgress', value: { loadedProgress: 'x' } }));
      delivery(frame, JSON.stringify({ method: 'unknown' }));

      expect(postMessage).not.toHaveBeenCalled();
      expect(events).toEqual([]);
      expect(playback.duration).toBeNaN();
      expect(playback.currentTime).toBe(0);
      playback.dispose();
    });
  });
});
