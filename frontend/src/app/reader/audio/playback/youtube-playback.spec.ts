import { PlaybackEvent } from './playback-element';
import { YouTubePlayback } from './youtube-playback';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';
const WIDGET = { id: 1, channel: 'widget' };
const LISTENING = { event: 'listening', ...WIDGET };
const WATCH_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
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

interface Fixture {
  playback: YouTubePlayback;
  host: HTMLElement;
  events: PlaybackEvent[];
  frame: HTMLIFrameElement;
  posted: unknown[];
}

function command(name: string, commandArguments: unknown[]): object {
  return { event: 'command', func: name, args: commandArguments, ...WIDGET };
}

function frameOf(host: HTMLElement): HTMLIFrameElement {
  const frame = host.querySelector('iframe');
  if (!frame) throw new Error('no frame in the host');
  return frame;
}

/** Replaces the frame's window with one that records what is posted to it. */
function recordPosts(frame: HTMLIFrameElement): unknown[] {
  const posted: unknown[] = [];
  const postMessage = jest.fn((message: string) => posted.push(JSON.parse(message)));
  Object.defineProperty(frame, 'contentWindow', { value: { postMessage }, configurable: true });
  return posted;
}

/** jsdom accepts only a real Window as a MessageEvent source, so the fake one is set afterwards. */
function delivery(frame: HTMLIFrameElement, data: unknown, origin = PLAYER_ORIGIN): void {
  const event = new MessageEvent('message', { data: JSON.stringify(data), origin });
  Object.defineProperty(event, 'source', { value: frame.contentWindow });
  window.dispatchEvent(event);
}

function playbackWithSource(url = WATCH_URL): Fixture {
  const host = document.createElement('div');
  document.body.appendChild(host);
  const playback = new YouTubePlayback(host);
  const events: PlaybackEvent[] = [];
  for (const type of ALL_EVENTS) playback.addEventListener(type, () => events.push(type));
  playback.src = url;
  const frame = frameOf(host);
  const posted = recordPosts(frame);
  return { playback, host, events, frame, posted };
}

function loadedPlayback(url?: string): Fixture {
  const fixture = playbackWithSource(url);
  fixture.frame.dispatchEvent(new Event('load'));
  return fixture;
}

function readyPlayback(url?: string): Fixture {
  const fixture = loadedPlayback(url);
  delivery(fixture.frame, { event: 'onReady', info: {} });
  return fixture;
}

describe('YouTubePlayback', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  describe('frame', () => {
    it('loads the privacy-enhanced embed of a watch url with the iframe API on', () => {
      const { playback, frame } = playbackWithSource(WATCH_URL);

      expect(frame.getAttribute('src')).toBe(
        `https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}&playsinline=1&controls=0`,
      );
      expect(frame.getAttribute('title')).toBe('YouTube');
      expect(playback.src).toBe(WATCH_URL);
      playback.dispose();
    });

    it('loads the embed of a youtu.be link', () => {
      const { playback, frame } = playbackWithSource('https://youtu.be/dQw4w9WgXcQ');

      expect(frame.getAttribute('src')).toContain(
        'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?enablejsapi=1',
      );
      playback.dispose();
    });

    it('asks the player to speak once the frame has loaded', () => {
      const { playback, frame, posted } = playbackWithSource();

      expect(posted).toEqual([]);
      frame.dispatchEvent(new Event('load'));

      expect(posted).toEqual([LISTENING]);
      playback.dispose();
    });
  });

  describe('play', () => {
    it('prompts the player again for a play asked before ready, then plays on initialDelivery', async () => {
      const { playback, frame, posted, events } = loadedPlayback();

      await playback.play();

      expect(posted).toEqual([LISTENING, LISTENING]);
      expect(events).toEqual(['play']);

      delivery(frame, { event: 'initialDelivery', info: { currentTime: 0 } });

      expect(posted.slice(2)).toEqual([command('setPlaybackRate', [1]), command('playVideo', [])]);
      playback.dispose();
    });

    it('plays on onReady as the first message as well', async () => {
      const { playback, frame, posted } = loadedPlayback();
      await playback.play();

      delivery(frame, { event: 'onReady', info: {} });

      expect(posted.slice(2)).toEqual([command('setPlaybackRate', [1]), command('playVideo', [])]);
      playback.dispose();
    });

    it('does not prompt the player again once it has spoken', async () => {
      const { playback, posted } = readyPlayback();

      await playback.play();

      expect(posted).toEqual([
        LISTENING,
        command('setPlaybackRate', [1]),
        command('playVideo', []),
      ]);
      playback.dispose();
    });

    it('seeks to a restored position before playing', async () => {
      const { playback, frame, posted } = loadedPlayback();
      playback.currentTime = 256;

      await playback.play();
      delivery(frame, { event: 'onReady', info: {} });

      expect(posted.slice(2)).toEqual([
        command('setPlaybackRate', [1]),
        command('seekTo', [256, true]),
        command('playVideo', []),
      ]);
      playback.dispose();
    });

    it('plays from the start without a seek', async () => {
      const { playback, posted } = readyPlayback();
      playback.currentTime = 0;

      await playback.play();

      expect(posted.slice(1)).toEqual([command('setPlaybackRate', [1]), command('playVideo', [])]);
      playback.dispose();
    });

    it('ignores a message that is not a widget message', async () => {
      const { playback, frame, posted } = loadedPlayback();
      await playback.play();

      window.dispatchEvent(
        Object.defineProperty(
          new MessageEvent('message', { data: 'not json', origin: PLAYER_ORIGIN }),
          'source',
          { value: frame.contentWindow },
        ),
      );
      delivery(frame, 42);

      expect(posted).toEqual([LISTENING, LISTENING]);
      playback.dispose();
    });
  });

  describe('pause and seek', () => {
    it('pauses the player once ready', async () => {
      const { playback, frame, posted } = readyPlayback();
      await playback.play();
      delivery(frame, { event: 'onStateChange', info: 1 });

      playback.pause();

      expect(posted.at(-1)).toEqual(command('pauseVideo', []));
      playback.dispose();
    });

    it('seeks at once after the player started playing', () => {
      const { playback, frame, posted } = readyPlayback();
      delivery(frame, { event: 'onStateChange', info: 1 });

      playback.currentTime = 30;

      expect(posted.at(-1)).toEqual(command('seekTo', [30, true]));
      playback.dispose();
    });
  });

  describe('messages', () => {
    it('reads time, duration, loaded fraction and the playing state from infoDelivery', () => {
      const { playback, frame, events } = readyPlayback();

      delivery(frame, {
        event: 'infoDelivery',
        info: { currentTime: 42.5, duration: 600, videoLoadedFraction: 0.25, playerState: 1 },
      });

      expect(playback.currentTime).toBe(42.5);
      expect(playback.duration).toBe(600);
      expect(playback.buffered.end(0)).toBe(150);
      expect(events).toEqual([
        'loadedmetadata',
        'durationchange',
        'progress',
        'timeupdate',
        'play',
      ]);
      playback.dispose();
    });

    it('reads only what an infoDelivery carries', () => {
      const { playback, frame, events } = readyPlayback();

      delivery(frame, { event: 'infoDelivery', info: { currentTime: 3 } });
      delivery(frame, { event: 'infoDelivery', info: { duration: 0, playerState: 3 } });
      delivery(frame, { event: 'infoDelivery', info: { currentTime: 'soon', duration: NaN } });
      delivery(frame, { event: 'infoDelivery' });

      expect(playback.currentTime).toBe(3);
      expect(playback.duration).toBeNaN();
      expect(events).toEqual(['timeupdate']);
      playback.dispose();
    });

    it('emits pause for state 2 and pause then ended for state 0', () => {
      const { playback, frame, events } = readyPlayback();
      delivery(frame, { event: 'onStateChange', info: 1 });

      delivery(frame, { event: 'onStateChange', info: 2 });
      expect(events).toEqual(['play', 'pause']);

      delivery(frame, { event: 'onStateChange', info: 1 });
      delivery(frame, { event: 'onStateChange', info: 0 });
      expect(events).toEqual(['play', 'pause', 'play', 'pause', 'ended']);
      playback.dispose();
    });

    it('emits nothing for buffering, cued and unstarted', () => {
      const { playback, frame, events } = readyPlayback();

      delivery(frame, { event: 'onStateChange', info: 3 });
      delivery(frame, { event: 'onStateChange', info: 5 });
      delivery(frame, { event: 'onStateChange', info: -1 });

      expect(events).toEqual([]);
      playback.dispose();
    });

    it('marks the element dead on a player error', () => {
      const { playback, frame, events } = readyPlayback();

      delivery(frame, { event: 'onError', info: 150 });

      expect(playback.error?.code).toBe(4);
      expect(events).toEqual(['error']);
      playback.dispose();
    });

    it('accepts nothing from www.youtube.com', async () => {
      const { playback, frame, posted, events } = loadedPlayback();
      await playback.play();

      delivery(frame, { event: 'onReady', info: {} }, 'https://www.youtube.com');
      delivery(frame, { event: 'onError', info: 150 }, 'https://www.youtube.com');

      expect(posted).toEqual([LISTENING, LISTENING]);
      expect(events).toEqual(['play']);
      expect(playback.error).toBeNull();
      playback.dispose();
    });
  });

  describe('playback rate', () => {
    it('sends the rate once ready and on every change', () => {
      const { playback, frame, posted } = loadedPlayback();
      playback.playbackRate = 1.5;
      expect(posted).toEqual([LISTENING]);

      delivery(frame, { event: 'onReady', info: {} });
      expect(posted.slice(1)).toEqual([command('setPlaybackRate', [1.5])]);

      playback.playbackRate = 2;
      expect(posted.slice(2)).toEqual([command('setPlaybackRate', [2])]);
      playback.dispose();
    });
  });

  describe('a new source', () => {
    it('prompts the fresh player again for a play asked before it has spoken', async () => {
      const { playback, host } = readyPlayback();

      playback.src = 'https://youtu.be/aaaaaaaaaaa';
      const frame = frameOf(host);
      const posted = recordPosts(frame);
      frame.dispatchEvent(new Event('load'));
      await playback.play();

      expect(posted).toEqual([LISTENING, LISTENING]);
      playback.dispose();
    });
  });
});
