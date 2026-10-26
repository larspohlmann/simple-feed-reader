import { WIDGET_URL } from '../../../../testing/embed-media-testing';
import {
  deliverMessage,
  messagesOf,
  playbackFixture,
  PlaybackFixture,
} from '../../../../testing/frame-playback-testing';
import { SOUNDCLOUD_WIDGET_ORIGIN, widgetMessage } from '../../media/soundcloud-widget-protocol';
import { SoundCloudPlayback } from './soundcloud-playback';

const WIDGET_FLAGS = 'auto_play=false&show_artwork=false&visual=false';
const READY_MESSAGES = [
  widgetMessage('addEventListener', 'play'),
  widgetMessage('addEventListener', 'pause'),
  widgetMessage('addEventListener', 'finish'),
  widgetMessage('addEventListener', 'playProgress'),
  widgetMessage('addEventListener', 'loadProgress'),
  widgetMessage('addEventListener', 'error'),
  widgetMessage('getDuration'),
];

type Fixture = PlaybackFixture<SoundCloudPlayback>;

function fromWidget(frame: HTMLIFrameElement, method: string, value?: unknown): void {
  deliverMessage(frame, JSON.stringify(widgetMessage(method, value)), SOUNDCLOUD_WIDGET_ORIGIN);
}

function playbackWithSource(url = WIDGET_URL): Fixture {
  return playbackFixture((host) => new SoundCloudPlayback(host), url);
}

function loadedPlayback(url?: string): Fixture {
  return playbackWithSource(url).loaded();
}

function readyPlayback(url?: string): Fixture {
  return loadedPlayback(url).ready(JSON.stringify(widgetMessage('ready')));
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
      const { playback, posted } = loadedPlayback();

      expect(posted).toEqual([]);
      playback.dispose();
    });

    it('subscribes to the widget events and asks for the length once the widget is ready', () => {
      const { playback, posted } = readyPlayback();

      expect(messagesOf(posted)).toEqual(READY_MESSAGES);
      expect(new Set(posted.map(({ origin }) => origin))).toEqual(
        new Set([SOUNDCLOUD_WIDGET_ORIGIN]),
      );
      playback.dispose();
    });

    it('becomes ready once, on the ready message only', () => {
      const { playback, frame, posted } = loadedPlayback();

      fromWidget(frame, 'loadProgress', { loadedProgress: 0.1 });
      expect(posted).toEqual([]);

      fromWidget(frame, 'ready');
      fromWidget(frame, 'ready');

      expect(messagesOf(posted)).toEqual(READY_MESSAGES);
      playback.dispose();
    });
  });

  describe('play', () => {
    it('plays once the widget is ready when asked before', async () => {
      const { playback, frame, events, posted } = loadedPlayback();

      await playback.play();

      expect(events).toEqual(['play']);
      expect(posted).toEqual([]);

      fromWidget(frame, 'ready');

      expect(messagesOf(posted)).toEqual([...READY_MESSAGES, widgetMessage('play')]);
      playback.dispose();
    });

    it('plays at once when the widget is ready', async () => {
      const { playback, posted } = readyPlayback();

      await playback.play();

      expect(messagesOf(posted)).toEqual([...READY_MESSAGES, widgetMessage('play')]);
      playback.dispose();
    });

    it('seeks to a restored position in milliseconds on the first play event, and only once', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 0;
      playback.currentTime = 256.5;

      await playback.play();
      expect(messagesOf(posted)).toEqual([...READY_MESSAGES, widgetMessage('play')]);

      fromWidget(frame, 'play');
      fromWidget(frame, 'play');

      expect(messagesOf(posted)).toEqual([
        ...READY_MESSAGES,
        widgetMessage('play'),
        widgetMessage('seekTo', 256500),
      ]);
      playback.dispose();
    });

    it('starts from the beginning without a seek', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 0;

      await playback.play();
      fromWidget(frame, 'play');

      expect(messagesOf(posted)).toEqual([...READY_MESSAGES, widgetMessage('play')]);
      playback.dispose();
    });

    it('forgets the restored position after a later play', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 12;
      await playback.play();
      fromWidget(frame, 'play');
      playback.pause();
      fromWidget(frame, 'pause');

      await playback.play();
      fromWidget(frame, 'play');

      expect(messagesOf(posted)).toEqual([
        ...READY_MESSAGES,
        widgetMessage('play'),
        widgetMessage('seekTo', 12000),
        widgetMessage('pause'),
        widgetMessage('play'),
      ]);
      playback.dispose();
    });

    it('emits play when the widget starts on its own', () => {
      const { playback, frame, events } = readyPlayback();

      fromWidget(frame, 'play');
      fromWidget(frame, 'play');

      expect(events).toEqual(['play']);
      playback.dispose();
    });
  });

  describe('pause', () => {
    it('pauses the widget and emits pause when the widget confirms', async () => {
      const { playback, frame, events, posted } = readyPlayback();
      await playback.play();
      fromWidget(frame, 'play');

      playback.pause();
      expect(messagesOf(posted)).toEqual([
        ...READY_MESSAGES,
        widgetMessage('play'),
        widgetMessage('pause'),
      ]);
      expect(events).toEqual(['play']);

      fromWidget(frame, 'pause');

      expect(events).toEqual(['play', 'pause']);
      playback.dispose();
    });
  });

  describe('seeking', () => {
    it('seeks in whole milliseconds once the sound plays', () => {
      const { playback, frame, posted } = readyPlayback();
      fromWidget(frame, 'play');

      playback.currentTime = 30;
      playback.currentTime = 12.3456;

      expect(messagesOf(posted)).toEqual([
        ...READY_MESSAGES,
        widgetMessage('seekTo', 30000),
        widgetMessage('seekTo', 12346),
      ]);
      playback.dispose();
    });

    it('reads the position the widget reports in seconds and emits timeupdate', () => {
      const { playback, frame, events } = readyPlayback();

      fromWidget(frame, 'playProgress', { currentPosition: 42500, relativePosition: 0.1 });

      expect(playback.currentTime).toBe(42.5);
      expect(events).toEqual(['timeupdate']);
      playback.dispose();
    });
  });

  describe('duration and buffering', () => {
    it('reads the length the widget answers in seconds', () => {
      const { playback, frame, events } = readyPlayback();

      fromWidget(frame, 'getDuration', 600000);

      expect(playback.duration).toBe(600);
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_METADATA);
      expect(events).toEqual(['loadedmetadata', 'durationchange']);
      playback.dispose();
    });

    it('spans the buffered range over the loaded share of the length', () => {
      const { playback, frame, events } = readyPlayback();
      fromWidget(frame, 'getDuration', 600000);

      fromWidget(frame, 'loadProgress', { loadedProgress: 0.25 });

      expect(playback.buffered.length).toBe(1);
      expect(playback.buffered.end(0)).toBe(150);
      expect(events).toEqual(['loadedmetadata', 'durationchange', 'progress']);
      playback.dispose();
    });
  });

  describe('ending and failing', () => {
    it('emits pause, then ended when the sound finishes', () => {
      const { playback, frame, events } = readyPlayback();
      fromWidget(frame, 'play');

      fromWidget(frame, 'finish');

      expect(events).toEqual(['play', 'pause', 'ended']);
      playback.dispose();
    });

    it('marks the element dead on a widget error', () => {
      const { playback, frame, events } = readyPlayback();

      fromWidget(frame, 'error');

      expect(playback.error?.code).toBe(4);
      expect(events).toEqual(['error']);
      playback.dispose();
    });
  });

  describe('playback rate', () => {
    it('sends no rate, the widget has none', () => {
      const { playback, frame, posted } = loadedPlayback();
      playback.playbackRate = 1.5;
      fromWidget(frame, 'ready');

      playback.playbackRate = 2;

      expect(messagesOf(posted)).toEqual(READY_MESSAGES);
      expect(playback.playbackRate).toBe(2);
      playback.dispose();
    });
  });

  describe('unwanted messages', () => {
    it('accepts nothing from the soundcloud.com site itself', () => {
      const { playback, frame, events, posted } = loadedPlayback();

      deliverMessage(frame, JSON.stringify(widgetMessage('ready')), 'https://soundcloud.com');
      deliverMessage(frame, JSON.stringify(widgetMessage('error')), 'https://soundcloud.com');

      expect(posted).toEqual([]);
      expect(events).toEqual([]);
      playback.dispose();
    });

    it('ignores what is not a widget message', () => {
      const { playback, frame, events, posted } = loadedPlayback();

      deliverMessage(frame, { method: 'ready' }, SOUNDCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, '{not json', SOUNDCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, JSON.stringify(null), SOUNDCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, JSON.stringify({ value: 'ready' }), SOUNDCLOUD_WIDGET_ORIGIN);
      fromWidget(frame, 'getDuration', 'long');
      fromWidget(frame, 'playProgress', null);
      fromWidget(frame, 'loadProgress', { loadedProgress: 'x' });
      fromWidget(frame, 'unknown');

      expect(posted).toEqual([]);
      expect(events).toEqual([]);
      expect(playback.duration).toBeNaN();
      expect(playback.currentTime).toBe(0);
      playback.dispose();
    });
  });
});
