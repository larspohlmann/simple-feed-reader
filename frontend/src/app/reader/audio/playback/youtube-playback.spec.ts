import { WATCH_URL } from '../../../../testing/embed-media-testing';
import {
  deliverMessage,
  frameOf,
  messagesOf,
  playbackFixture,
  PlaybackFixture,
  recordPosts,
} from '../../../../testing/frame-playback-testing';
import {
  LISTENING,
  playerCommand,
  YOUTUBE_PLAYER_ORIGIN,
} from '../../media/youtube-player-protocol';
import { YouTubePlayback } from './youtube-playback';

const READY = { event: 'onReady', info: {} };

type Fixture = PlaybackFixture<YouTubePlayback>;

function delivery(frame: HTMLIFrameElement, data: unknown, origin = YOUTUBE_PLAYER_ORIGIN): void {
  deliverMessage(frame, JSON.stringify(data), origin);
}

function playbackWithSource(url = WATCH_URL): Fixture {
  return playbackFixture((host) => new YouTubePlayback(host), url);
}

function loadedPlayback(url?: string): Fixture {
  return playbackWithSource(url).loaded();
}

function readyPlayback(url?: string): Fixture {
  return loadedPlayback(url).ready(JSON.stringify(READY));
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

      expect(posted).toEqual([{ message: LISTENING, origin: YOUTUBE_PLAYER_ORIGIN }]);
      playback.dispose();
    });
  });

  describe('play', () => {
    it('prompts the player again for a play asked before ready, then plays on initialDelivery', async () => {
      const { playback, frame, posted, events } = loadedPlayback();

      await playback.play();

      expect(messagesOf(posted)).toEqual([LISTENING, LISTENING]);
      expect(events).toEqual(['play']);

      delivery(frame, { event: 'initialDelivery', info: { currentTime: 0 } });

      expect(messagesOf(posted).slice(2)).toEqual([
        playerCommand('setPlaybackRate', [1]),
        playerCommand('playVideo', []),
      ]);
      playback.dispose();
    });

    it('plays on onReady as the first message as well', async () => {
      const { playback, frame, posted } = loadedPlayback();
      await playback.play();

      delivery(frame, READY);

      expect(messagesOf(posted).slice(2)).toEqual([
        playerCommand('setPlaybackRate', [1]),
        playerCommand('playVideo', []),
      ]);
      playback.dispose();
    });

    it('does not prompt the player again once it has spoken', async () => {
      const { playback, posted } = readyPlayback();

      await playback.play();

      expect(messagesOf(posted)).toEqual([
        LISTENING,
        playerCommand('setPlaybackRate', [1]),
        playerCommand('playVideo', []),
      ]);
      playback.dispose();
    });

    it('seeks to a restored position before playing', async () => {
      const { playback, frame, posted } = loadedPlayback();
      playback.currentTime = 256;

      await playback.play();
      delivery(frame, READY);

      expect(messagesOf(posted).slice(2)).toEqual([
        playerCommand('setPlaybackRate', [1]),
        playerCommand('seekTo', [256, true]),
        playerCommand('playVideo', []),
      ]);
      playback.dispose();
    });

    it('plays from the start without a seek', async () => {
      const { playback, posted } = readyPlayback();
      playback.currentTime = 0;

      await playback.play();

      expect(messagesOf(posted).slice(1)).toEqual([
        playerCommand('setPlaybackRate', [1]),
        playerCommand('playVideo', []),
      ]);
      playback.dispose();
    });

    it('ignores a message that is not a widget message', async () => {
      const { playback, frame, posted } = loadedPlayback();
      await playback.play();

      deliverMessage(frame, 'not json', YOUTUBE_PLAYER_ORIGIN);
      delivery(frame, 42);

      expect(messagesOf(posted)).toEqual([LISTENING, LISTENING]);
      playback.dispose();
    });
  });

  describe('pause and seek', () => {
    it('pauses the player once ready', async () => {
      const { playback, frame, posted } = readyPlayback();
      await playback.play();
      delivery(frame, { event: 'onStateChange', info: 1 });

      playback.pause();

      expect(messagesOf(posted).at(-1)).toEqual(playerCommand('pauseVideo', []));
      playback.dispose();
    });

    it('seeks at once after the player started playing', () => {
      const { playback, frame, posted } = readyPlayback();
      delivery(frame, { event: 'onStateChange', info: 1 });

      playback.currentTime = 30;

      expect(messagesOf(posted).at(-1)).toEqual(playerCommand('seekTo', [30, true]));
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

      delivery(frame, READY, 'https://www.youtube.com');
      delivery(frame, { event: 'onError', info: 150 }, 'https://www.youtube.com');

      expect(messagesOf(posted)).toEqual([LISTENING, LISTENING]);
      expect(events).toEqual(['play']);
      expect(playback.error).toBeNull();
      playback.dispose();
    });
  });

  describe('playback rate', () => {
    it('sends the rate once ready and on every change', () => {
      const { playback, frame, posted } = loadedPlayback();
      playback.playbackRate = 1.5;
      expect(messagesOf(posted)).toEqual([LISTENING]);

      delivery(frame, READY);
      expect(messagesOf(posted).slice(1)).toEqual([playerCommand('setPlaybackRate', [1.5])]);

      playback.playbackRate = 2;
      expect(messagesOf(posted).slice(2)).toEqual([playerCommand('setPlaybackRate', [2])]);
      playback.dispose();
    });
  });

  describe('a new source', () => {
    it('builds the fresh player on play and prompts it again for a play before it has spoken', async () => {
      const { playback, host } = readyPlayback();

      playback.src = 'https://youtu.be/aaaaaaaaaaa';
      await playback.play();
      const frame = frameOf(host);
      const posted = recordPosts(frame);
      frame.dispatchEvent(new Event('load'));
      await playback.play();

      expect(frame.getAttribute('src')).toContain('/embed/aaaaaaaaaaa?');
      expect(messagesOf(posted)).toEqual([LISTENING, LISTENING]);
      playback.dispose();
    });
  });
});
